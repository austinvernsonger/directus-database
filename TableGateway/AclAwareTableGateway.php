<?php

namespace Directus\Db\TableGateway;

use Directus\Acl\Acl;
use Directus\Acl\Exception\UnauthorizedTableAddException;
use Directus\Acl\Exception\UnauthorizedTableBigDeleteException;
use Directus\Acl\Exception\UnauthorizedTableBigEditException;
use Directus\Acl\Exception\UnauthorizedTableDeleteException;
use Directus\Acl\Exception\UnauthorizedTableEditException;
use Directus\Auth\Provider as Auth;
use Directus\Bootstrap;
use Directus\Db\Exception\SuppliedArrayAsColumnValue;
use Directus\Db\Hooks;
use Directus\Db\RowGateway\AclAwareRowGateway;
use Directus\Db\TableSchema;
use Directus\Util\Date;
use Directus\Util\Formatting;
use Zend\Db\Adapter\AdapterInterface;
use Zend\Db\Sql\AbstractSql;
use Zend\Db\Sql\Delete;
use Zend\Db\Sql\Insert;
use Zend\Db\Sql\Select;
use Zend\Db\Sql\Sql;
use Zend\Db\Sql\Update;
use Zend\Db\Sql\Where;
use Zend\Db\TableGateway\Feature;
use Zend\Db\TableGateway\Feature\RowGatewayFeature;
use Directus\MemcacheProvider;

class AclAwareTableGateway extends \Zend\Db\TableGateway\TableGateway {

    protected $acl;

    public $primaryKeyFieldName = "id";
    public $imagickExtensions = array('tif', 'psd', 'pdf');
    public $memcache;

    /**
     * Constructor
     *
     * @param AclProvider $acl
     * @param string $table
     * @param AdapterInterface $adapter
     * @param Feature\AbstractFeature|Feature\FeatureSet|Feature\AbstractFeature[] $features
     * @param ResultSetInterface $resultSetPrototype
     * @param Sql $sql
     * @throws Exception\InvalidArgumentException
     */
    public function __construct(Acl $acl, $table, AdapterInterface $adapter, $features = null, ResultSetInterface $resultSetPrototype = null, Sql $sql = null)
    {
        $this->acl = $acl;

        // process features
        if ($features !== null) {
            if ($features instanceof Feature\AbstractFeature) {
                $features = array($features);
            }
            if (is_array($features)) {
                $this->featureSet = new Feature\FeatureSet($features);
            } elseif ($features instanceof Feature\FeatureSet) {
                $this->featureSet = $features;
            } else {
                throw new Exception\InvalidArgumentException(
                    'TableGateway expects $feature to be an instance of an AbstractFeature or a FeatureSet, or an array of AbstractFeatures'
                );
            }
        } else {
            $this->featureSet = new Feature\FeatureSet();
        }

        $rowGatewayPrototype = new AclAwareRowGateway($acl, $this->primaryKeyFieldName, $table, $adapter);
        $rowGatewayFeature = new RowGatewayFeature($rowGatewayPrototype);
        $this->featureSet->addFeature($rowGatewayFeature);
        $this->memcache = new MemcacheProvider();

        parent::__construct($table, $adapter, $this->featureSet, $resultSetPrototype, $sql);
    }

    /**
     * Static Factory Methods
     */

    /**
     * Underscore to camelcase table name to namespaced table gateway classname,
     * e.g. directus_users => \Directus\Db\TableGateway\DirectusUsersTableGateway
     */
    public static function makeTableGatewayFromTableName($acl, $table, $adapter) {
        $tableGatewayClassName = Formatting::underscoreToCamelCase($table) . "TableGateway";
        $tableGatewayClassName = __NAMESPACE__ . "\\$tableGatewayClassName";
        if(class_exists($tableGatewayClassName)) {
            return new $tableGatewayClassName($acl, $adapter);
        }
        return new self($acl, $table, $adapter);
    }

    /**
     * HELPER FUNCTIONS
     */

    public function withKey($key, $resultSet) {
        $withKey = array();
        foreach($resultSet as $row) {
            $withKey[$row[$key]] = $row;
        }
        return $withKey;
    }

    protected function convertResultSetDateTimesTimeZones(array $resultSet, $targetTimeZone, $fields = array('datetime'), $yieldObjects = false) {
        foreach($resultSet as &$result) {
            $result = $this->convertRowDateTimesToTimeZone($result, $targetTimeZone, $fields);
        }
        return $resultSet;
    }

    protected function convertRowDateTimesToTimeZone(array $row, $targetTimeZone, $fields = array('datetime'), $yieldObjects = false) {
        foreach($fields as $field) {
            $col =& $row[$field];
            $datetime = Date::convertUtcDateTimeToTimeZone($col, $targetTimeZone);
            $col = $yieldObjects ? $datetime : $datetime->format("Y-m-d H:i:s T");
        }
        return $row;
    }

    public function newRow($table = null, $pk_field_name = null)
    {
        $table = is_null($table) ? $this->table : $table;
        $pk_field_name = is_null($pk_field_name) ? $this->primaryKeyFieldName : $pk_field_name;
        $row = new AclAwareRowGateway($this->acl, $pk_field_name, $table, $this->adapter);
        return $row;
    }

    public function find($id, $pk_field_name = "id") {
        $record = $this->findOneBy($pk_field_name, $id);
        return $record;
    }

    public function findActive($id, $pk_field_name = "id") {
        $rowset = $this->select(function(Select $select) use ($pk_field_name, $id) {
            $select->limit(1);
            $select
                ->where
                    ->equalTo($pk_field_name, $id)
                    ->AND
                    ->equalTo('active', AclAwareRowGateway::ACTIVE_STATE_ACTIVE);
        });
        $row = $rowset->current();
        // Supposing this "one" doesn't exist in the DB
        if(false === $row) {
            return false;
        }
        $row = $row->toArray();
        // Tmp removal note, this breaks things, cannot use:
        // array_walk($row, array($this, 'castFloatIfNumeric'));
        return $row;
    }

    public function fetchAll($selectModifier = null) {
        return $this->select(function(Select $select) use ($selectModifier) {
            if(is_callable($selectModifier)) {
                $selectModifier($select);
            }
        });
    }

    /**
     * @return array All rows in array form with record IDs for the array's keys.
     */
    public function fetchAllWithIdKeys($selectModifier = null) {
        $allWithIdKeys = array();
        $all = $this->fetchAll($selectModifier)->toArray();
        return $this->withKey('id', $all);
    }

    public function fetchAllActiveSort($sort = null, $dir = "ASC") {
        return $this->select(function(Select $select) use ($sort, $dir) {
            $select->where->equalTo("active", 1);
            if(!is_null($sort)) {
                $select->order("$sort $dir");
            }
        });
    }

    public function findOneBy($field, $value) {
        $rowset = $this->select(function(Select $select) use ($field, $value) {
            $select->limit(1);
            $select->where->equalTo($field, $value);
        });
        $row = $rowset->current();
        // Supposing this "one" doesn't exist in the DB
        if(false === $row) {
            return false;
        }
        $row = $row->toArray();
        return $row;
    }

    public function addOrUpdateRecordByArray(array $recordData, $tableName = null) {
        foreach($recordData as $columnName => $columnValue) {
            if(is_array($columnValue)) {
                $table = is_null($tableName) ? $this->table : $tableName;
                throw new SuppliedArrayAsColumnValue("Attempting to write an array as the value for column `$table`.`$columnName`.");
            }
        }

        $tableName = is_null($tableName) ? $this->table : $tableName;
        $rowExists = isset($recordData['id']);

        // $record = AclAwareRowGateway::makeRowGatewayFromTableName($this->acl, $tableName, $this->adapter);
        // $record->populateSkipAcl($recordData, $rowExists);
        // $record->populate($recordData, $rowExists);
        // $record->save();
        // return $record;

        $TableGateway = new self($this->acl, $tableName, $this->adapter);
        if($rowExists) {
            $Update = new Update($tableName);
            $Update->set($recordData);
            $Update->where(array('id' => $recordData['id']));
            $TableGateway->updateWith($Update);
            // Post-update hook
            Hooks::runHook('postUpdate', array($TableGateway, $recordData, $this->adapter, $this->acl));
        } else {
            //If we are adding a new directus_media Item, We need to do that logic
            if($tableName == "directus_media") {
              $Storage = new \Directus\Media\Storage\Storage();

              //If trying to save to temp, force to default
              if((!isset($recordData['storage_adapter']) || $recordData['storage_adapter'] == '') || $Storage->storageAdaptersByRole['TEMP']['id'] == $recordData['storage_adapter']) {
                $recordData['storage_adapter'] = $Storage->storageAdaptersByRole['DEFAULT']['id'];
              }

              //Save Temp Thumbnail name for use after media record save
              $info = pathinfo($recordData['name']);
              if( in_array($info['extension'], $this->imagickExtensions)) {
                $thumbnailName = "THUMB_".$info['filename'].'.jpg';
              } else {
                $thumbnailName = "THUMB_".$recordData['name'];
              }

              //If we are using Media ID, Dont save until after insert
              if($Storage->getMediaSettings()['media_file_naming'] != "media_id") {
                //Save the file in TEMP Storage Adapter to Designated StorageAdapter
                $recordData['name'] = $Storage->saveFile($recordData['name'], $recordData['storage_adapter']);
              }
            }

            $TableGateway->insert($recordData);
            $recordData['id'] = $TableGateway->getLastInsertValue();

            if($tableName == "directus_media") {
              $ext = pathinfo($recordData['name'], PATHINFO_EXTENSION);
              $updateArray = array();
              //If using MediaId saving, then update record and set name to id
              if($Storage->getMediaSettings()['media_file_naming'] == "media_id") {
                $newName = $Storage->saveFile($recordData['name'], $recordData['storage_adapter'], str_pad($recordData['id'],11,"0", STR_PAD_LEFT).'.'.$ext);
                $updateArray['name'] = str_pad($recordData['id'],11,"0", STR_PAD_LEFT).'.'.$ext;
                $recordData['name'] = $updateArray['name'];
              }

              //If we are using media_id titles, then set title to id
              if($Storage->getMediaSettings()['media_title_naming'] == "media_id") {
                $updateArray['title'] = str_pad($recordData['id'],11,"0", STR_PAD_LEFT);
                $recordData['title'] = $updateArray['title'];
              }

              if(!empty($updateArray)) {
                $Update = new Update($tableName);
                $Update->set($updateArray);
                $Update->where(array('id' => $recordData['id']));
                $TableGateway->updateWith($Update);
              }

              //Save Temp Thumbnail to Thumbnail SA using media id: $params['id']
              $tempLocation = $Storage->storageAdaptersByRole['TEMP']['destination'];
              if(file_exists($tempLocation.$thumbnailName)) {
                $thumbnailDestination = $Storage->storageAdaptersByRole['THUMBNAIL']['destination'];
                if(in_array($ext, $this->imagickExtensions)) {
                  $ext = 'jpg';
                }
                $Storage->ThumbnailStorage->acceptFile($tempLocation.$thumbnailName, $recordData['id'].".".$ext, $thumbnailDestination);
              }
            }

            // Post-insert hook
            Hooks::runHook('postInsert', array($TableGateway, $recordData, $this->adapter, $this->acl));
        }

        $columns = TableSchema::getAllNonAliasTableColumnNames($tableName);
        $recordData = $TableGateway->fetchAll(function($select) use ($recordData, $columns) {
            $select
                ->columns($columns)
                ->limit(1);
            $select->where->equalTo('id', $recordData['id']);
        })->current();

        return $recordData;
    }

    protected function logger() {
        return Bootstrap::get('app')->getLog();
    }

    public function castFloatIfNumeric(&$value) {
        $value = is_numeric($value) ? (float) $value : $value;
    }

    /**
     * Convenience method for dumping a ZendDb Sql query object as debug output.
     * @param  AbstractSql $query
     * @return null
     */
    public function dumpSql(AbstractSql $query) {
        $sql = new Sql($this->adapter);
        $query = $sql->getSqlStringForSqlObject($query, $this->adapter->getPlatform());
        return $query;
    }

    /**
     * Extract unescaped & unprefixed column names
     * @param  array $columns Optionally escaped or table-prefixed column names, e.g. drawn from
     * \Zend\Db\Sql\Insert|\Zend\Db\Sql\Update#getRawState
     * @return array
     */
    protected function extractRawColumnNames($columns) {
        $columnNames = array();
        foreach ($insertState['columns'] as $column) {
            $sansSpaces = preg_replace('/\s/', '', $column);
            preg_match('/(\W?\w+\W?\.)?\W?([\*\w+])\W?/', $sansSpaces, $matches);
            if(isset($matches[2])) {
                $columnNames[] = $matches[2];
            }
        }
        return $columnNames;
    }

    protected function getRawTableNameFromQueryStateTable($table) {
        if(is_string($table)) {
            return $table;
        }
        if(is_array($table)) {
            // The only value is the real table name (key is alias).
            return array_pop($table);
        }
        throw new \InvalidArgumentException("Unexpected parameter of type " . get_class($table));
    }

    /**
     * OVERRIDES
     */

    /**
     * @param Select $select
     * @return ResultSet
     * @throws \RuntimeException
     */
    protected function executeSelect(Select $select)
    {
        /**
         * ACL Enforcement
         */
        $selectState = $select->getRawState();
        $table = $this->getRawTableNameFromQueryStateTable($selectState['table']);

        // Enforce field read blacklist on Select's main table
        $this->acl->enforceBlacklist($table, $selectState['columns'], Acl::FIELD_READ_BLACKLIST);

        // Enforce field read blacklist on Select's join tables
        foreach($selectState['joins'] as $join) {
            $joinTable = $this->getRawTableNameFromQueryStateTable($join['name']);
            $this->acl->enforceBlacklist($joinTable, $join['columns'], Acl::FIELD_READ_BLACKLIST);
        }

        try {
            return parent::executeSelect($select);
        } catch(\Zend\Db\Adapter\Exception\InvalidQueryException $e) {
            if('production' !== DIRECTUS_ENV) {
                throw new \RuntimeException("This query failed: " . $this->dumpSql($select), 0, $e);
            }
            // @todo send developer warning
            throw $e;
        }
    }

    /**
     * @param Insert $insert
     * @return mixed
     * @throws \Directus\Acl\Exception\UnauthorizedTableAddException
     * @throws \Directus\Acl\Exception\UnauthorizedFieldWriteException
     */
    protected function executeInsert(Insert $insert)
    {
        /**
         * ACL Enforcement
         */

        $insertState = $insert->getRawState();
        $insertTable = $this->getRawTableNameFromQueryStateTable($insertState['table']);

        if(!$this->acl->hasTablePrivilege($insertTable, 'add')) {
            $aclErrorPrefix = $this->acl->getErrorMessagePrefix();
            throw new UnauthorizedTableAddException($aclErrorPrefix . "Table add access forbidden on table $insertTable");
        }

        // Enforce write field blacklist (if user lacks bigedit privileges on this table)
        if(!$this->acl->hasTablePrivilege($insertTable, 'bigedit')) {
            // Parsing for the column name is unnecessary. Zend enforces raw column names.
            // $rawColumns = $this->extractRawColumnNames($insertState['columns']);

            //@TODO: Clean up this hacky way of forcing active column to == 2 if write table blacklisted active
            $isInactive = false;
            if(in_array("active", $insertState['columns'])) {
              //If inactive by default and active blacklisted
              if(in_array('active', $this->acl->getTablePrivilegeList($insertTable, Acl::FIELD_WRITE_BLACKLIST))) {
                if(TableSchema::getTable($insertState['table'])['inactive_by_default']) {
                  $isInactive = true;
                }
                //Unset active columns so it can bypass Blacklist check (and uses table default)
                $insertState['columns'] = array_diff($insertState['columns'], array('active'));
              }
            }
            $this->acl->enforceBlacklist($insertTable, $insertState['columns'], Acl::FIELD_WRITE_BLACKLIST);

            //If forcing to inactive, make it inactive
            if($isInactive) {
              $insertState['columns']['active'] = 2;
            }
        }

        try {
            return parent::executeInsert($insert);
        } catch(\Zend\Db\Adapter\Exception\InvalidQueryException $e) {
            if('production' !== DIRECTUS_ENV) {
                throw new \RuntimeException("This query failed: " . $this->dumpSql($insert), 0, $e);
            }
            // @todo send developer warning
            throw $e;
        }
    }

    /**
     * @param Update $update
     * @return mixed
     * @throws Exception\RuntimeException
     * @throws \Directus\Acl\Exception\UnauthorizedFieldWriteException
     * @throws \Directus\Acl\Exception\UnauthorizedTableBigEditException
     * @throws \Directus\Acl\Exception\UnauthorizedTableEditException
     */
    protected function executeUpdate(Update $update)
    {
        $currentUserId = null;
        if(Auth::loggedIn()) {
            $currentUser = Auth::getUserInfo();
            $currentUserId = intval($currentUser['id']);
        }
        $updateState = $update->getRawState();
        $updateTable = $this->getRawTableNameFromQueryStateTable($updateState['table']);
        $cmsOwnerColumn = $this->acl->getCmsOwnerColumnByTable($updateTable);

        /**
         * ACL Enforcement
         */

        if(!$this->acl->hasTablePrivilege($updateTable, 'bigedit')) {
            // Parsing for the column name is unnecessary. Zend enforces raw column names.
            // $rawColumns = $this->extractRawColumnNames($updateState['columns']);
            /**
             * Enforce Privilege: "Big" Edit
             */
            if(false === $cmsOwnerColumn) {
                // All edits are "big" edits if there is no magic owner column.
                $aclErrorPrefix = $this->acl->getErrorMessagePrefix();
                throw new UnauthorizedTableBigEditException($aclErrorPrefix . "Table bigedit access forbidden on table `$updateTable` (no magic owner column).");
            } else {
                // Who are the owners of these rows?
                list($resultQty, $ownerIds) = $this->acl->getCmsOwnerIdsByTableGatewayAndPredicate($this, $updateState['where']);
                // Enforce
                if(is_null($currentUserId) || count(array_diff($ownerIds, array($currentUserId)))) {
                    $aclErrorPrefix = $this->acl->getErrorMessagePrefix();
                    throw new UnauthorizedTableBigEditException($aclErrorPrefix . "Table bigedit access forbidden on $resultQty `$updateTable` table record(s) and " . count($ownerIds) . " CMS owner(s) (with ids " . implode(", ", $ownerIds) . ").");
                }
            }

            /**
             * Enforce write field blacklist (if user lacks bigedit privileges on this table)
             */
            $attemptOffsets = array_keys($updateState['set']);
            $this->acl->enforceBlacklist($updateTable, $attemptOffsets, Acl::FIELD_WRITE_BLACKLIST);
        }

        if(!$this->acl->hasTablePrivilege($updateTable, 'edit')) {
            /**
             * Enforce Privilege: "Little" Edit (I am the record CMS owner)
             */
            if(false !== $cmsOwnerColumn) {
                if(!isset($predicateResultQty)) {
                    // Who are the owners of these rows?
                    list($predicateResultQty, $predicateOwnerIds) = $this->acl->getCmsOwnerIdsByTableGatewayAndPredicate($this, $updateState['where']);
                }
                if(in_array($currentUserId, $predicateOwnerIds)) {
                    $aclErrorPrefix = $this->acl->getErrorMessagePrefix();
                    throw new UnauthorizedTableEditException($aclErrorPrefix . "Table edit access forbidden on $predicateResultQty `$updateTable` table records owned by the authenticated CMS user (#$currentUserId).");
                }
            }
        }

        try {
            return parent::executeUpdate($update);
        } catch(\Zend\Db\Adapter\Exception\InvalidQueryException $e) {
            if('production' !== DIRECTUS_ENV) {
                throw new \RuntimeException("This query failed: " . $this->dumpSql($update), 0, $e);
            }
            // @todo send developer warning
            throw $e;
        }
    }

    /**
     * @param Delete $delete
     * @return mixed
     * @throws Exception\RuntimeException
     * @throws \Directus\Acl\Exception\UnauthorizedTableBigDeleteException
     * @throws \Directus\Acl\Exception\UnauthorizedTableDeleteException
     */
    protected function executeDelete(Delete $delete)
    {
        $cuurrentUserId = null;
        if(Auth::loggedIn()) {
            $currentUser = Auth::getUserInfo();
            $currentUserId = intval($currentUser['id']);
        }
        $deleteState = $delete->getRawState();
        $deleteTable = $this->getRawTableNameFromQueryStateTable($deleteState['table']);
        $cmsOwnerColumn = $this->acl->getCmsOwnerColumnByTable($deleteTable);

        /**
         * ACL Enforcement
         */

        if(!$this->acl->hasTablePrivilege($deleteTable, 'bigdelete')) {
            /**
             * Enforce Privilege: "Big" Delete
             */
            if(false === $cmsOwnerColumn) {
                // All deletes are "big" deletes if there is no magic owner column.
                $aclErrorPrefix = $this->acl->getErrorMessagePrefix();
                throw new UnauthorizedTableBigDeleteException($aclErrorPrefix . "Table bigdelete access forbidden on table `$deleteTable` (no magic owner column).");
            } else {
                // Who are the owners of these rows?
                list($predicateResultQty, $predicateOwnerIds) = $this->acl->getCmsOwnerIdsByTableGatewayAndPredicate($this, $deleteState['where']);
                // Enforce
                if(is_null($currentUserId) || count(array_diff($predicateOwnerIds, array($currentUserId)))) {
                    $aclErrorPrefix = $this->acl->getErrorMessagePrefix();
                    throw new UnauthorizedTableBigDeleteException($aclErrorPrefix . "Table bigdelete access forbidden on $predicateResultQty `$deleteTable` table record(s) and " . count($predicateOwnerIds) . " CMS owner(s) (with ids " . implode(", ", $predicateOwnerIds) . ").");
                }
            }
        }

        if(!$this->acl->hasTablePrivilege($deleteTable, 'delete')) {
            /**
             * Enforce Privilege: "Little" Delete (I am the record CMS owner)
             */
            if(false !== $cmsOwnerColumn) {
                if(!isset($predicateResultQty)) {
                    // Who are the owners of these rows?
                    list($predicateResultQty, $predicateOwnerIds) = $this->acl->getCmsOwnerIdsByTableGatewayAndPredicate($this, $deleteState['where']);
                }
                if(in_array($currentUserId, $predicateOwnerIds)) {
                    $exceptionMessage = "Table delete access forbidden on $predicateResultQty `$deleteTable` table records owned by the authenticated CMS user (#$currentUserId).";
                    $aclErrorPrefix = $this->acl->getErrorMessagePrefix();
                    throw new UnauthorizedTableDeleteException($aclErrorPrefix . $exceptionMessage);
                }
            }
        }

        try {
            return parent::executeDelete($delete);
        } catch(\Zend\Db\Adapter\Exception\InvalidQueryException $e) {
            if('production' !== DIRECTUS_ENV) {
                throw new \RuntimeException("This query failed: " . $this->dumpSql($delete), 0, $e);
            }
            // @todo send developer warning
            throw $e;
        }
    }
}
