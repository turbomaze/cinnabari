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

namespace Datto\Cinnabari\Exception;

class SchemaException extends AbstractException
{
    // schema errors
    const NO_CLASS = 1;
    const NO_PROPERTY = 2;
    const NO_LIST = 3;
    const NO_VALUE = 4;
    const NO_CONNECTION = 5;

    public static function noClass($class)
    {
        $code = self::NO_CLASS;
        $data = array('class' => $class);
        $classString = json_encode($class);
        $message = "Class {$classString} does not exist.";
        return new self($code, $data, $message);
    }

    public static function noProperty($class, $property)
    {
        $code = self::NO_PROPERTY;
        $data = array('class' => $class, 'property' => $property);
        $classString = json_encode($class);
        $propertyString = json_encode($property);
        $message = "Property {$propertyString} of class {$classString} does not exist.";
        return new self($code, $data, $message);
    }

    public static function noList($list)
    {
        $code = self::NO_LIST;
        $data = array('list' => $list);
        $listString = json_encode($list);
        $message = "List {$listString} does not exist.";
        return new self($code, $data, $message);
    }

    public static function noValue($value, $tableId)
    {
        $code = self::NO_VALUE;
        $data = array('value' => $value, 'tableIdentifier' => $tableId);
        $valueString = json_encode($value);
        $tableString = json_encode($tableId);
        $message = "Value {$valueString} of table {$tableString} does not exist.";
        return new self($code, $data, $message);
    }

    public static function noConnection($connection, $tableId)
    {
        $code = self::NO_CONNECTION;
        $data = array('connection' => $connection, 'tableIdentifier' => $tableId);
        $connectionString = json_encode($connection);
        $message = "Connection {$connectionString} does not exist.";
        return new self($code, $data, $message);
    }
}
