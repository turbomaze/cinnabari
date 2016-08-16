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

class Select extends AbstractMysql
{
    /** @var string[] */
    protected $columns;

    public function __construct()
    {
        parent::__construct();
        $this->columns = array();
    }

    public function getMysql()
    {
        if (!$this->isValid()) {
            throw CompilerException::invalidSelect();
        }

        $mysql = $this->getColumns() .
            $this->getTables() .
            $this->getWhereClause() .
            $this->getOrderByClause() .
            $this->getLimitClause();

        return rtrim($mysql, "\n");
    }

    public function addExpression(AbstractExpression $expression)
    {
        return self::insert($this->columns, $expression->getMysql());
    }

    public function setLimit(AbstractExpression $start, AbstractExpression $length)
    {
        $offset = $start->getMysql();
        $count = $length->getMysql();
        $mysql = "{$offset}, {$count}";

        $this->limit = $mysql;
    }

    public function setOrderBy($tableId, $column, $isAscending)
    {
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

    public function addValue($tableId, $column)
    {
        $table = self::getIdentifier($tableId);
        $name = self::getAbsoluteExpression($table, $column);

        return self::insert($this->columns, $name);
    }

    protected function getColumns()
    {
        return "SELECT\n\t" . implode(",\n\t", $this->getColumnNames()) . "\n";
    }

    protected function getColumnNames()
    {
        $columns = array();

        foreach ($this->columns as $name => $id) {
            $columns[] = self::getAliasedName($name, $id);
        }

        return $columns;
    }

    protected function getTables()
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

        return $mysql;
    }

    protected function isValid()
    {
        return (0 < count($this->tables)) && (0 < count($this->columns));
    }

    protected static function getAliasedName($name, $id)
    {
        $alias = self::getIdentifier($id);
        return "{$name} AS {$alias}";
    }
}
