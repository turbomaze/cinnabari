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
 * @author Anthony Liu <aliu@datto.com>
 * @license http://www.gnu.org/licenses/lgpl-3.0.html LGPL-3.0
 * @copyright 2016 Datto, Inc.
 */

namespace Datto\Cinnabari\Mysql;

use Datto\Cinnabari\Exception\CompilerException;
use Datto\Cinnabari\Mysql\Expression\AbstractExpression;

class Insert extends AbstractMysql
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
            throw CompilerException::invalidInsert();
        }

        $mysql = "INSERT\n" .
            $this->getTables() .
            $this->getColumnValuePairs();

        return rtrim($mysql, "\n");
    }

    public function addPropertyValuePair($tableId, $column, $expression)
    {
        if (!self::isDefined($this->tables, $tableId)) {
            throw CompilerException::badTableId($tableId);
        }

        $name = self::getColumnNameFromExpression($column->getMysql());

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

        $mysql = "\tINTO " . $table . "\n";

        return $mysql;
    }

    protected function isValid()
    {
        return (0 < count($this->tables)) && (0 < count($this->columns)) && (count($this->columns) === count($this->values));
    }

    private static function getColumnNameFromExpression($expression)
    {
        preg_match("/`[a-z0-9_$]+`/i", $expression, $matches);
        if (count($matches) === 0) {
            return $expression;
        } else {
            return $matches[0];
        }
    }
}
