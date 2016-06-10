<?php

/**
 * Copyright (C) 2016 Datto, Inc.
 *
 * This file is part of Cinnabari.
 *
 * Cinnabari is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Lesser General Public License, version 3,
 * as published by the Free Software Foundation.
 *
 * Cinnabari is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Lesser General Public License for more details.
 *
 * You should have received a copy of the GNU Lesser General Public License
 * along with Cinnabari. If not, see <http://www.gnu.org/licenses/>.
 *
 * @author Spencer Mortensen <smortensen@datto.com>
 * @license http://www.gnu.org/licenses/lgpl-3.0.html LGPL-3.0
 * @copyright 2016 Datto, Inc.
 */

namespace Datto\Cinnabari\Mysql;

use Datto\Cinnabari\Mysql\Expression\AbstractExpression;

class Select
{
    const JOIN_INNER = 1;
    const JOIN_LEFT = 2;

    /** @var string[] */
    private $tables;

    /** @var string[] */
    private $columns;

    /** @var AbstractExpression */
    private $where;

    /** @var string */
    private $orderBy;

    public function __construct()
    {
        $this->tables = array();
        $this->columns = array();
        $this->where = null;
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

    public function addJoin($tableAId, $tableBIdentifier, $mysqlExpression, $type)
    {
        if (!self::isDefined($this->tables, $tableAId)) {
            return null;
        }

        $tableIdentifierA = self::getIdentifier($tableAId);

        $key = json_encode(array($tableIdentifierA, $tableBIdentifier, $mysqlExpression, $type));

        return self::insert($this->tables, $key);
    }

    public function setWhere(AbstractExpression $expression)
    {
        $this->where = $expression;
    }

    public function setOrderBy($tableId, $column, $isAscending)
    {
        if (!self::isDefined($this->tables, $tableId)) {
            return null;
        }

        $table = self::getIdentifier($tableId);
        $name = self::getAbsoluteExpression($table, $column);

        $mysql = "ORDER BY {$name} ";

        if ($isAscending) {
            $mysql .= "ASC";
        } else {
            $mysql .= "DESC";
        }

        $this->orderBy = $mysql;
    }

    public function addValue($tableId, $column)
    {
        if (!self::isDefined($this->tables, $tableId)) {
            return null;
        }

        $table = self::getIdentifier($tableId);
        $name = self::getAbsoluteExpression($table, $column);

        return self::insert($this->columns, $name);
    }

    public function getMysql()
    {
        if (!$this->isValid()) {
            return null;
        }

        $mysql = $this->getColumns() .
            $this->getTables() .
            $this->getWhereClause();

        return rtrim($mysql, "\n");
    }

    public function getTable($id)
    {
        $name = array_search($id, $this->tables, true);

        if (!is_string($name)) {
            return null;
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

    private static function insert(&$array, $key)
    {
        $id = &$array[$key];

        if (!isset($id)) {
            $id = count($array) - 1;
        }

        return $id;
    }

    private static function isDefined($array, $id)
    {
        return is_int($id) && (-1 < $id) && ($id < count($array));
    }

    private static function getIdentifier($name)
    {
        return "`{$name}`";
    }

    private function isValid()
    {
        return (0 < count($this->tables)) && (0 < count($this->columns));
    }

    private function getColumns()
    {
        return "SELECT\n\t" . implode(",\n\t", $this->getColumnNames()) . "\n";
    }

    private function getColumnNames()
    {
        $columns = array();

        foreach ($this->columns as $name => $id) {
            $columns[] = self::getAliasedName($name, $id);
        }

        return $columns;
    }

    private function getTables()
    {
        list($table, $id) = each($this->tables);

        $mysql = "\tFROM " . self::getAliasedName($table, $id) . "\n";

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

        if (isset($this->orderBy)) {
            $mysql .= "\t{$this->orderBy}\n";
        }

        return $mysql;
    }

    private function getWhereClause()
    {
        if ($this->where === null) {
            return null;
        }

        $where = $this->where->getMysql();
        return "\tWHERE {$where}\n";
    }

    private static function getAliasedName($name, $id)
    {
        $alias = self::getIdentifier($id);
        return "{$name} AS {$alias}";
    }
}
