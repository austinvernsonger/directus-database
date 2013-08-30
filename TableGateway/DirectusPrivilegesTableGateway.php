<?php

namespace Directus\Db\TableGateway;

use Directus\Acl\Acl;
use Directus\Db\TableGateway\AclAwareTableGateway;
use Zend\Db\Adapter\AdapterInterface;
use Zend\Db\Sql\Sql;
use Zend\Db\Sql\Select;

class DirectusPrivilegesTableGateway extends AclAwareTableGateway {

    public static $_tableName = "directus_privileges";

    public function __construct(Acl $acl, AdapterInterface $adapter) {
        parent::__construct($acl, self::$_tableName, $adapter);
    }

    public function fetchGroupPrivileges($group_id) {
        $select = new Select($this->table);
        $select->where->equalTo('group_id', $group_id);
        $rowset = $this->selectWith($select);
        $rowset = $rowset->toArray();
        $privilegesByTable = array();
        foreach($rowset as $row) {
            foreach($row as $field => &$value) {
                if($this->acl->isTableListValue($field))
                    $value = explode(",", $value);
                $privilegesByTable[$row['table_name']] = $row;
            }
        }
        return $privilegesByTable;
    }


    public function fetchGroupPrivilegesRaw($group_id) {
        $select = new Select($this->table);
        $select->where->equalTo('group_id', $group_id);
        $rowset = $this->selectWith($select);
        $rowset = $rowset->toArray();
        return $rowset;
    }
}
