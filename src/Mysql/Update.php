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

use Datto\Cinnabari\Exception\CompilerException;
use Datto\Cinnabari\Mysql\Expression\AbstractExpression;

class Update extends AbstractMysql
{
    /** @var string[] */
    protected $columns;

    /** @var string[] */
    protected $values;

    public function __construct()
    {
        parent::__construct();
        $this->columns = array();
        $this->values = array();
    }

    public function getMysql()
    {
        if (!$this->isValid()) {
            throw CompilerException::invalidUpdate();
        }

        $mysql = "UPDATE\n" .
            $this->getTables() .
            $this->getColumnValuePairs() .
            $this->getWhereClause() .
            $this->getOrderByClause() .
            $this->getLimitClause();

        return rtrim($mysql, "\n");
    }

    public function setLimit($tableId, AbstractExpression $start, AbstractExpression $length)
    {
        if (!self::isDefined($this->tables, $tableId)) {
            return null;
        }

        $offset = $start->getMysql();
        $count = $length->getMysql();
        $mysql = "{$offset}, {$count}";

        $this->limit = $mysql;
    }

    public function setOrderBy($tableId, $column, $isAscending)
    {
        if (!self::isDefined($this->tables, $tableId)) {
            throw CompilerException::badTableId($tableId);
        }

        $table = $this->getIdentifier($tableId);
        $name = self::getAbsoluteExpression($table, $column);

        $mysql = "ORDER BY {$name}";

        if ($isAscending) {
            $mysql .= " ASC";
        } else {
            $mysql .= " DESC";
        }

        $this->orderBy = $mysql;
    }

    public function addPropertyValuePair($tableId, $column, $expression)
    {
        if (!self::isDefined($this->tables, $tableId)) {
            throw CompilerException::badTableId($tableId);
        }

        $table = self::getIdentifier($tableId);
        $name = self::getAbsoluteExpression($table, $column->getMysql());

        $this->values[$name] = $expression->getMysql();
        return self::insert($this->columns, $name);
    }

    protected function getColumnValuePairs()
    {
        $pairs = array();

        foreach ($this->columns as $column => $id) {
            $pairs[] = $column . ' = ' . $this->values[$column];
        }

        return "\tSET\n\t\t" . implode(",\n\t\t", $pairs) . "\n";
    }

    protected function getTables()
    {
        list($table, $id) = each($this->tables);

        $mysql = "\t" . self::getAliasedName($table, $id) . "\n";

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

    protected function isValid()
    {
        return (0 < count($this->tables)) && (0 < count($this->columns)) && (count($this->columns) === count($this->values));
    }

    protected static function getAliasedName($name, $id)
    {
        $alias = self::getIdentifier($id);
        return "{$name} AS {$alias}";
    }
}

