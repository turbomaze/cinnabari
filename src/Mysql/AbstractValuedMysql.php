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
 * @author Anthony Liu <aliu@datto.com>
 * @license http://www.gnu.org/licenses/lgpl-3.0.html LGPL-3.0
 * @copyright 2016 Datto, Inc.
 */

namespace Datto\Cinnabari\Mysql;

use Datto\Cinnabari\Mysql\Expression\AbstractExpression;
use Datto\Cinnabari\Mysql\Expression\Column;

abstract class AbstractValuedMysql extends AbstractMysql
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
    
    abstract public function addPropertyValuePair($tableId, Column $column, AbstractExpression $expression);

    protected function isValid()
    {
        return (0 < count($this->tables)) &&
            (0 < count($this->columns)) &&
            (count($this->columns) === count($this->values));
    }
}
