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

namespace Datto\Cinnabari\Compiler;

use Datto\Cinnabari\Mysql\AbstractValuedMysql;
use Datto\Cinnabari\Mysql\Expression\Column;
use Datto\Cinnabari\Translator;

/**
 * Class AbstractValuedCompiler
 * @package Datto\Cinnabari
 */
abstract class AbstractValuedCompiler extends AbstractCompiler
{
    /** @var AbstractValuedMysql */
    protected $mysql;

    protected function enterTable()
    {
        $firstElement = array_shift($this->request);
        list(, $token) = each($firstElement);

        $this->context = $this->mysql->setTable($token['table']);

        return true;
    }

    protected function readList()
    {
        if (!$this->scanList($this->request, $list)) {
            return false;
        }

        $initialContext = $this->context;
        foreach ($list as $index => $pair) {
            $this->context = $initialContext;
            $property = $pair['property'];
            $this->getColumnFromPropertyPath($property, $column, $columnType, $hasZero);

            $this->context = $initialContext;
            $value = $pair['value'];

            $this->getExpression($value, $hasZero, $expression, $expressionType);

            $this->mysql->addPropertyValuePair($this->context, $column, $expression);
        }

        return true;
    }

    protected function getColumnFromPropertyPath($arrayToken, &$output, &$type, &$hasZero)
    {
        $arrayToken = $this->followJoins($arrayToken);
        $propertyToken = reset($arrayToken);
        list(, $property) = each($propertyToken);
        $output = new Column($property['expression']);
        $type = $property['type'];
        $hasZero = $property['hasZero'];
        return true;
    }

    protected function scanList($input, &$object)
    {
        list($tokenType, $token) = each($input);

        if ($tokenType !== Translator::TYPE_LIST) {
            return false;
        }

        $object = $token;
        return true;
    }
}
