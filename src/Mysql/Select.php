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
    /** @var string[] */
    private $tables;

    /** @var string[] */
    private $columns;

    /** @var AbstractExpression */
    private $where;

    public function __construct()
    {
        $this->tables = array();
        $this->columns = array();
        $this->where = null;
    }

    public function setTable($name)
    {
        $countTables = count($this->tables);

        if (0 < $countTables) {
            return null;
        }

        return self::insert($this->tables, $name);
    }

    private static function insert(&$array, $key)
    {
        $id = &$array[$key];

        if (!isset($id)) {
            $id = count($array) - 1;
        }

        return $id;
    }

    public function addColumn($tableId, $column)
    {
        if (!is_int($tableId) || ($tableId < 0)) {
            return null;
        }

        $countTables = count($this->tables);

        if ($countTables <= $tableId) {
            return null;
        }

        $table = self::getIdentifier($tableId);
        $name = self::getAbsoluteExpression($table, $column);

        return self::insert($this->columns, $name);
    }

    public function setWhere(AbstractExpression $expression)
    {
        $this->where = $expression;
    }

    private static function getIdentifier($name)
    {
        return "`{$name}`";
    }

    public static function getAbsoluteExpression($context, $expression)
    {
        return preg_replace('~`.*?`~', "{$context}.\$0", $expression);
    }

    public function getMysql()
    {
        if (!$this->isValid()) {
            return null;
        }

        $table = $this->getTable();
        $columns = "\t" . implode(",\n\t", $this->getColumns());

        $mysql = "SELECT\n{$columns}\n\tFROM {$table}";

        if ($this->where !== null) {
            $where = $this->where->getMysql();
            $mysql .= "\n\tWHERE {$where}";
        }

        return $mysql;
    }

    private function isValid()
    {
        return (0 < count($this->tables)) && (0 < count($this->columns));
    }

    private function getTable()
    {
        list($name, $id) = each($this->tables);
        return self::getAliasedName($name, $id);
    }

    private function getColumns()
    {
        $columns = array();

        foreach ($this->columns as $name => $id) {
            $columns[] = self::getAliasedName($name, $id);
        }

        return $columns;
    }

    private static function getAliasedName($name, $id)
    {
        $alias = self::getIdentifier($id);
        return "{$name} AS {$alias}";
    }
}
