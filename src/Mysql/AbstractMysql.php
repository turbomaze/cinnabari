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

abstract class AbstractMysql
{
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

    public function __construct()
    {
        $this->tables = array();
        $this->where = null;
        $this->orderBy = null;
        $this->limit = null;
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
        $joinType = (!$hasZero && !$hasMany) ? self::JOIN_INNER : self::JOIN_LEFT;
        $tableAIdentifier = self::getIdentifier($tableAId);
        $key = json_encode(array($tableAIdentifier, $tableBIdentifier, $mysqlExpression, $joinType));

        return self::insert($this->tables, $key);
    }

    public function getTable($id)
    {
        $name = array_search($id, $this->tables, true);

        if (!is_string($name)) {
            throw CompilerException::badTableId($id);
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
        list($table, ) = each($this->tables);

        $mysql = "\tFROM " . $table . "\n";

        $tables = array_slice($this->tables, 1);

        foreach ($tables as $joinJson => $id) {
            list($tableAIdentifier, $tableBIdentifier, $expression, $type) = json_decode($joinJson, true);

            $joinIdentifier = self::getIdentifier($id);

            $splitExpression = explode(' ', $expression);
            $newExpression = array();
            $from = array('`0`', '`1`');
            $to = array($tableAIdentifier, $joinIdentifier);

            foreach ($splitExpression as $key => $token) {
                for ($i = 0; $i < count($from); $i++) {
                    $token = str_replace($from[$i], $to[$i], $token, $count);
                    if ($count > 0) {
                        break;
                    }
                }
                $newExpression[] = $token;
            }
            $expression = implode(' ', $newExpression);

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

    protected static function getColumnNameFromExpression($expression)
    {
        preg_match("/`[a-z0-9_$]+`/i", $expression, $matches);
        if (count($matches) === 0) {
            return $expression;
        } else {
            return $matches[0];
        }
    }
}
