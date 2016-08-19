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
use Datto\Cinnabari\Mysql\Expression\Table;

abstract class AbstractMysql
{
    const JOIN_INNER = 1;
    const JOIN_LEFT = 2;

    /** @var AbstractMysql[]|AbstractExpression[] */
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
     * @param AbstractExpression|AbstractMysql $expression
     * Mysql abstract expression (e.g. new Table("`People`"))
     * Mysql abstract mysql (e.g. new Select())
     *
     * @return int
     * Numeric table identifier (e.g. 0)
     */
    public function setTable($expression)
    {
        $countTables = count($this->tables);

        if (0 < $countTables) {
            return null;
        }

        return self::appendOrFind($this->tables, $expression);
    }

    public function setWhere(AbstractExpression $expression)
    {
        $this->where = $expression;
    }

    public function addJoin($tableAId, $tableBIdentifier, $mysqlExpression, $hasZero, $hasMany)
    {
        $joinType = (!$hasZero && !$hasMany) ? self::JOIN_INNER : self::JOIN_LEFT;
        $tableAIdentifier = self::getIdentifier($tableAId);
        $join = new Table(json_encode(array($tableAIdentifier, $tableBIdentifier, $mysqlExpression, $joinType)));
        return self::appendOrFind($this->tables, $join);
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

    public static function getIdentifier($name)
    {
        return "`{$name}`";
    }

    // insert if not already there; return index
    protected static function insert(&$array, $key)
    {
        $id = &$array[$key];

        if (!isset($id)) {
            $id = count($array) - 1;
        }

        return $id;
    }

    protected static function appendOrFind(&$array, $value)
    {
        $index = array_search($value, $array);
        if ($index === false) {
            $index = count($array);
            $array[] = $value;
        }
        return $index;
    }

    protected function isValid()
    {
        return (0 < count($this->tables)) || isset($this->subquery);
    }

    protected function getTables()
    {
        $table = reset($this->tables);

        $mysql = "\tFROM " . $table . "\n";

        for ($id = 1; $id < count($this->tables); $id++) {
            $joinJson = $this->tables[$id];
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

    protected static function indent($string)
    {
        return "\t" . preg_replace('~\n(?!\n)~', "\n\t", $string);
    }
}
