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

class Delete extends AbstractMysql
{
    public function getMysql()
    {
        if (!$this->isValid()) {
            throw CompilerException::invalidDelete();
        }

        $mysql = "DELETE\n" .
            $this->getTables() .
            $this->getWhereClause() .
            $this->getOrderByClause() .
            $this->getLimitClause();

        return rtrim($mysql, "\n");
    }

    public function setOrderBy($tableId, $column, $isAscending)
    {
        $table = $this->tables[$tableId];
        $name = self::getAbsoluteExpression($table, $column);

        $mysql = "ORDER BY {$name}";

        if ($isAscending) {
            $mysql .= " ASC";
        } else {
            $mysql .= " DESC";
        }

        $this->orderBy = $mysql;
    }

    public function setLimit(AbstractExpression $length)
    {
        $this->limit = $length->getMysql();
    }

    protected function isValid()
    {
        return (0 < count($this->tables));
    }
}
