<?php

namespace Datto\Cinnabari\Mysql;

use Datto\Cinnabari\Exception\AbstractException;
use Datto\Cinnabari\Mysql\Expression\AbstractExpression;

abstract class AbstractMySql
{
    // mysql errors
    const ERROR_BAD_TABLE_ID = 201;
    const ERROR_INVALID_MYSQL = 202;

    const JOIN_INNER = 1;
    const JOIN_LEFT = 2;

    /** @var string[] */
    protected $tables;

    /** @var AbstractExpression */
    protected $where;

    /** @var string */
    protected $orderBy;

    /** @var string */
    protected $limit;

    /** @var array */
    protected $rollbackPoint;

    public function __construct()
    {
        $this->tables = array();
        $this->where = null;
        $this->orderBy = null;
        $this->limit = null;
        $this->rollbackPoint = array();
    }

    /**
     * @param string $name
     * Mysql table identifier (e.g. "`people`")
     *
     * @return int
     * Numeric table identifier (e.g. 0)
     */
    public function setTable($name)
    {
        $countTables = count($this->tables);

        if (0 < $countTables) {
            return null;
        }

        return self::insert($this->tables, $name);
    }

    public function setWhere(AbstractExpression $expression)
    {
        $this->where = $expression;
    }

    public function findTable($name)
    {
        if (array_key_exists($name, $this->tables)) {
            return $this->tables[$name];
        } else {
            return false;
        }
    }

    public function addJoin($tableAId, $tableBIdentifier, $mysqlExpression, $hasZero, $hasMany)
    {
        if (!self::isDefined($this->tables, $tableAId)) {
            return null;
        }
        $joinType = (!$hasZero && !$hasMany) ? self::JOIN_INNER : self::JOIN_LEFT;
        $tableAIdentifier = self::getIdentifier($tableAId);
        $key = json_encode(array($tableAIdentifier, $tableBIdentifier, $mysqlExpression, $joinType));
        return self::insert($this->tables, $key);
    }

    public function getTable($id)
    {
        $name = array_search($id, $this->tables, true);

        if (!is_string($name)) {
            $idString = json_decode($id);
            throw new AbstractException(
                self::ERROR_BAD_TABLE_ID,
                array(
                    'tableId' => $id,
                    'name' => $name
                ),
                "unknown table id {$idString}."
            );
        }

        if (0 < $id) {
            list(, $name) = json_decode($name);
        }

        return $name;
    }

    public static function getAbsoluteExpression($context, $expression)
    {
        return preg_replace('~`.*?`~', "{$context}.\$0", $expression);
    }

    protected static function insert(&$array, $key)
    {
        $id = &$array[$key];

        if (!isset($id)) {
            $id = count($array) - 1;
        }

        return $id;
    }

    protected static function isDefined($array, $id)
    {
        return is_int($id) && (-1 < $id) && ($id < count($array));
    }

    protected static function getIdentifier($name)
    {
        return "`{$name}`";
    }

    protected function isValid()
    {
        return (0 < count($this->tables));
    }

    protected function getTables()
    {
        reset($this->tables);
        list($table, $id) = each($this->tables);

        $mysql = "\tFROM " . $table . "\n";

        $tables = array_slice($this->tables, 1);

        foreach ($tables as $joinJson => $id) {
            list($tableAIdentifier, $tableBIdentifier, $expression, $type) = json_decode($joinJson, true);

            $joinIdentifier = self::getIdentifier($id);

            $from = array('`0`', '`1`');
            $to = array($tableAIdentifier, $joinIdentifier);
            $expression = str_replace($from, $to, $expression);

            if ($type === self::JOIN_INNER) {
                $mysqlJoin = 'INNER JOIN';
            } else {
                $mysqlJoin = 'LEFT JOIN';
            }

            $mysql .= "\t{$mysqlJoin} {$tableBIdentifier} AS {$joinIdentifier} ON {$expression}\n";
        }

        return $mysql;
    }

    protected function getWhereClause()
    {
        if ($this->where === null) {
            return null;
        }

        $where = $this->where->getMysql();
        return "\tWHERE {$where}\n";
    }

    protected function getOrderByClause()
    {
        if ($this->orderBy === null) {
            return null;
        }

        return "\t{$this->orderBy}\n";
    }

    protected function getLimitClause()
    {
        if ($this->limit === null) {
            return null;
        }

        return "\tLIMIT {$this->limit}\n";
    }

    public function setRollbackPoint()
    {
        $this->rollbackPoint[] = array(count($this->tables));
    }

    public function clearRollbackPoint()
    {
        array_pop($this->rollbackPoint);
    }

    public function rollback()
    {
        $rollbackState = array_pop($this->rollbackPoint);
        $this->tables = array_slice($this->tables, 0, $rollbackState[0]);
    }
}
