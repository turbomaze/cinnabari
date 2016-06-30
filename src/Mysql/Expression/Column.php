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

namespace Datto\Cinnabari\Mysql\Expression;

class Column extends AbstractExpression
{
    /** @var string */
    private $context;

    /** @var string */
    private $columnName;

    /** @var string */
    private $dataType;

    /** @var boolean */
    private $isNullable;

    public function __construct($context, $columnName, $dataType, $isNullable)
    {
        $this->context = $context;
        $this->columnName = $columnName;
        $this->dataType = $dataType;
        $this->isNullable = $isNullable;
    }

    public function getDataType()
    {
        return $this->dataType;
    }

    public function getMysql()
    {
        return "`{$this->context}`" . '.' . $this->columnName;
    }

    public function getIsNullable()
    {
        return $this->isNullable;
    }
}
