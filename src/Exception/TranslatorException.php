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
 * @author Anthony Liu <igliu@mit.edu>
 * @license http://www.gnu.org/licenses/lgpl-3.0.html LGPL-3.0
 * @copyright 2016 Datto, Inc.
 */

namespace Datto\Cinnabari\Exception;

class TranslatorException extends AbstractException
{
    // schema errors
    const UNKNOWN_PROPERTY = 1;
    const UNKNOWN_LIST = 2;
    const UNKNOWN_CONNECTION = 3;
    const UNKNOWN_VALUE = 4;
    const UNKNOWN_CONTEXT = 5;

    public static function unknownProperty($class, $property)
    {
        $code = self::UNKNOWN_PROPERTY;

        $data = array(
            'class' => $class,
            'property' => $property
        );

        $className = json_encode($class);
        $propertyName = json_encode($property);

        $message = "Unknown property {$propertyName} in class {$className}.";

        return new self($code, $data, $message);
    }

    public static function unknownList($list)
    {
        $code = self::UNKNOWN_LIST;

        $data = array(
            'list' => $list
        );

        $listName = json_encode($list);

        $message = "Unknown list {$listName}.";

        return new self($code, $data, $message);
    }

    public static function unknownConnection($table, $connection)
    {
        $code = self::UNKNOWN_CONNECTION;

        $data = array(
            'table' => $table,
            'connection' => $connection
        );

        $connectionName = json_encode($connection);
        $tableName = json_encode($table);

        $message = "Unknown connection {$connectionName} in table {$tableName}.";

        return new self($code, $data, $message);
    }

    public static function unknownValue($table, $value)
    {
        $code = self::UNKNOWN_VALUE;

        $data = array(
            'table' => $table,
            'value' => $value
        );

        $tableName = json_encode($table);
        $valueName = json_encode($value);

        $message = "Unknown value {$valueName} in table {$tableName}.";

        return new self($code, $data, $message);
    }

    public static function unknownContext($function, $arguments)
    {
        $code = self::UNKNOWN_CONTEXT;

        $data = array(
            'function' => $function,
            'arguments' => $arguments
        );

        $functionName = json_encode($function);

        $message = "Expected the token {$functionName} to describe a table context.";

        return new self($code, $data, $message);
    }
}
