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

namespace Datto\Cinnabari;

class Translator
{
    const TYPE_PARAMETER = 1;
    const TYPE_FUNCTION = 3;
    const TYPE_OBJECT = 4;
    const TYPE_TABLE = 5;
    const TYPE_JOIN = 6;
    const TYPE_VALUE = 7;

    const EXCEPTION_UNKNOWN_PROPERTY = 1;
    const EXCEPTION_UNKNOWN_LIST = 2;
    const EXCEPTION_UNKNOWN_CONNECTION = 3;
    const EXCEPTION_UNKNOWN_VALUE = 4;

    /** @var array */
    private $schema;

    public function __construct($schema)
    {
        $this->schema = $schema;
    }

    // NOTE: check for a valid $request before this method is executed
    public function translate($request)
    {
        $this->getExpression('Database', null, $request, $expression);

        return $expression;
    }

    private function getExpression($class, $table, $tokens, &$output)
    {
        foreach ($tokens as $token) {
            $type = $token[0];

            switch ($type) {
                case Parser::TYPE_PARAMETER:
                    $parameter = $token[1];
                    self::getParameter($parameter, $output);
                    break;

                case Parser::TYPE_PROPERTY:
                    $property = $token[1];
                    $this->getProperty($class, $table, $property, $output);
                    break;

                case Parser::TYPE_FUNCTION:
                    $function = $token[1];
                    $arguments = array_slice($token, 2);
                    $this->getFunction($class, $table, $function, $arguments, $output);
                    break;

                default: // Parser::TYPE_OBJECT:
                    $object = $token[1];
                    $this->getObject($class, $table, $object, $output);
                    break;
            }
        }
    }

    private static function getParameter($parameter, &$output)
    {
        $output[] = array(
            self::TYPE_PARAMETER => $parameter
        );
    }

    private function getProperty(&$class, &$table, $property, &$output)
    {
        list($type, $path) = $this->getPropertyDefinition($class, $property);
        $isPrimitiveProperty = is_int($type);

        if ($table === null) {
            $list = array_shift($path);
            $this->getList($table, $list, $output);
        }

        $value = $isPrimitiveProperty ? array_pop($path) : null;

        foreach ($path as $connection) {
            $this->getConnection($table, $connection, $output);
        }

        if ($isPrimitiveProperty) {
            $this->getValue($table, $value, $type, $output);
        } else {
            $class = $type;
        }
    }

    private function getList(&$table, $list, &$output)
    {
        list($table, $id, $hasZero) = $this->getListDefinition($list);

        $output[] = array(
            self::TYPE_TABLE => array(
                'table' => $table,
                'id' => $id,
                'hasZero' => $hasZero
            )
        );
    }

    private function getConnection(&$table, $connection, &$output)
    {
        $definition = $this->getConnectionDefinition($table, $connection);
        
        $output[] = array(
            self::TYPE_JOIN => array(
                'tableA' => $table,
                'tableB' => $definition[0],
                'expression' => $definition[1],
                'id' => $definition[2],
                'hasZero' => $definition[3],
                'hasMany' => $definition[4]
            )
        );

        $table = $definition[0];
    }

    private function getValue($table, $value, $type, &$output)
    {
        list($expression, $hasZero) = $this->getValueDefinition($table, $value);

        $output[] = array(
            self::TYPE_VALUE => array(
                'table' => $table,
                'expression' => $expression,
                'type' => $type,
                'hasZero' => $hasZero
            )
        );
    }

    private function getFunction(&$class, &$table, $function, $arguments, &$output)
    {
        $output[] = array(
            self::TYPE_FUNCTION => array(
                'function' => $function,
                'arguments' => $this->translateArray($class, $table, $arguments)
            )
        );
    }

    private function getObject(&$class, &$table, $object, &$output)
    {
        $output[] = array(
            self::TYPE_OBJECT => $this->translateArray($class, $table, $object)
        );
    }

    private function translateArray(&$class, &$table, $input)
    {
        $output = array();

        foreach ($input as $key => $value) {
            $this->getExpression($class, $table, $value, $output[$key]);
        }

        return $output;
    }

    private function getPropertyDefinition($class, $property)
    {
        $definition = &$this->schema['classes'][$class][$property];

        if ($definition === null) {
            throw self::exceptionUnknownProperty($class, $property);
        }

        $type = reset($definition);
        $path = array_slice($definition, 1);

        return array($type, $path);
    }

    private function getListDefinition($list)
    {
        $definition = &$this->schema['lists'][$list];

        if ($definition === null) {
            throw self::exceptionUnknownList($list);
        }

        return $definition;
    }

    private function getConnectionDefinition($table, $connection)
    {
        $definition = &$this->schema['connections'][$table][$connection];

        if ($definition === null) {
            throw self::exceptionUnknownConnection($table, $connection);
        }

        return $definition;
    }

    private function getValueDefinition($table, $value)
    {
        $definition = &$this->schema['values'][$table][$value];

        if ($definition === null) {
            throw self::exceptionUnknownValue($table, $value);
        }

        return $definition;
    }

    private static function exceptionUnknownProperty($class, $property)
    {
        $code = self::EXCEPTION_UNKNOWN_PROPERTY;

        $data = array(
            'class' => $class,
            'property' => $property
        );

        $className = json_encode($class);
        $propertyName = json_encode($property);

        $message = "Unknown property {$propertyName} in class {$className}";

        return new Exception($code, $data, $message);
    }

    private static function exceptionUnknownList($list)
    {
        $code = self::EXCEPTION_UNKNOWN_LIST;

        $data = array(
            'list' => $list
        );

        $listName = json_encode($list);

        $message = "Unknown list {$listName}";

        return new Exception($code, $data, $message);
    }

    private static function exceptionUnknownConnection($table, $connection)
    {
        $code = self::EXCEPTION_UNKNOWN_CONNECTION;

        $data = array(
            'table' => $table,
            'connection' => $connection
        );

        $connectionName = json_encode($connection);
        $tableName = json_encode($table);

        $message = "Unknown connection {$connectionName} in table {$tableName}";

        return new Exception($code, $data, $message);
    }

    private static function exceptionUnknownValue($table, $value)
    {
        $code = self::EXCEPTION_UNKNOWN_VALUE;

        $data = array(
            'table' => $table,
            'value' => $value
        );

        $tableName = json_encode($table);
        $valueName = json_encode($value);

        $message = "Unknown value {$valueName} in table {$tableName}";

        return new Exception($code, $data, $message);
    }
}
