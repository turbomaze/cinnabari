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

namespace Datto\Cinnabari;

use Datto\Cinnabari\Exception\TranslatorException;

class Translator
{
    const TYPE_PARAMETER = 1;
    const TYPE_FUNCTION = 3;
    const TYPE_OBJECT = 4;
    const TYPE_LIST = 5;
    const TYPE_TABLE = 6;
    const TYPE_JOIN = 7;
    const TYPE_VALUE = 8;

    /** @var array */
    private $schema;

    public function __construct($schema)
    {
        $this->schema = $schema;
    }

    // NOTE: check for a valid $request before this method is executed
    public function translateIgnoringObjects($request)
    {
        return $this->translate($request, false);
    }

    public function translateIncludingObjects($request)
    {
        return $this->translate($request, true);
    }

    private function translate($request, $translateObjectKeys)
    {
        $this->getExpression($translateObjectKeys, 'Database', null, $request, $expression);

        return $expression;
    }

    private function getExpression($translateObjectKeys, $class, $table, $tokens, &$output)
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
                    self::scanFunction($token, $function, $arguments);
                    switch ($function) {
                        case 'get':
                        case 'count':
                        case 'average':
                        case 'sum':
                        case 'min':
                        case 'max':
                        case 'delete':
                        case 'set':
                        case 'insert':
                            $this->getListFunction(
                                $translateObjectKeys,
                                $class,
                                $table,
                                $function,
                                $arguments,
                                $output
                            );
                            break;

                        default:
                            $this->getFunction(
                                $translateObjectKeys,
                                $class,
                                $table,
                                $function,
                                $arguments,
                                $output
                            );
                            break;
                    }
                    break;

                default: // Parser::TYPE_OBJECT:
                    $object = $token[1];
                    $this->getObject($translateObjectKeys, $class, $table, $object, $output);
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

    private function getFunction($translateObjectKeys, &$class, &$table, $function, $arguments, &$output)
    {
        $output[] = array(
            self::TYPE_FUNCTION => array(
                'function' => $function,
                'arguments' => $this->translateArray($translateObjectKeys, $class, $table, $arguments)
            )
        );
    }

    private function getListFunction($translateObjectKeys, &$class, &$table, $function, $arguments, &$output)
    {
        $firstArgument = array_shift($arguments);

        if (!isset($firstArgument) || (count($firstArgument) < 1)) {
            throw TranslatorException::unknownContext($function, $arguments);
        }

        $firstArgument = reset($firstArgument);

        if (!isset($firstArgument) || (count($firstArgument) < 2)) {
            throw TranslatorException::unknownContext($function, $arguments);
        }

        $firstArgumentType = $firstArgument[0];

        if ($firstArgumentType === Parser::TYPE_PROPERTY) {
            $property = $firstArgument[1];
            $this->getProperty($class, $table, $property, $output);
            $this->getFunction($translateObjectKeys, $class, $table, $function, $arguments, $output);
            return $property;
        } else {
            self::scanFunction($firstArgument, $childFunction, $childArguments);

            $property = $this->getListFunction(
                $translateObjectKeys,
                $class,
                $table,
                $childFunction,
                $childArguments,
                $output
            );

            $output[] = array(
                self::TYPE_FUNCTION => array(
                    'function' => $function,
                    'arguments' => $this->translateArray(
                        $translateObjectKeys,
                        $class,
                        $table,
                        $arguments
                    )
                )
            );
            
            return $property;
        }
    }

    private function getObject($translateObjectKeys, &$class, &$table, $object, &$output)
    {
        if ($translateObjectKeys) {
            $output[] = array(
                self::TYPE_LIST => $this->translateKeysAndArray(
                    $translateObjectKeys,
                    $class,
                    $table,
                    $object
                )
            );
        } else {
            $output[] = array(
                self::TYPE_OBJECT => $this->translateArray(
                    $translateObjectKeys,
                    $class,
                    $table,
                    $object
                )
            );
        }
    }

    private function translateArray($translateObjectKeys, &$class, &$table, $input)
    {
        $output = array();

        foreach ($input as $key => $value) {
            $this->getExpression($translateObjectKeys, $class, $table, $value, $output[$key]);
        }

        return $output;
    }

    private function translateKeysAndArray($translateObjectKeys, &$class, &$table, $input)
    {
        $output = array();
        $properties = array();
        $values = array();

        foreach ($input as $key => $value) {
            $propertyList = self::stringToPropertyList($key);
            $translatedKey = array();
            $translatedValue = array();
            $this->getExpression(
                $translateObjectKeys,
                $class,
                $table,
                $propertyList,
                $translatedKey
            );
            $this->getExpression(
                $translateObjectKeys,
                $class,
                $table,
                $value,
                $translatedValue
            );
            $properties[] = $translatedKey;
            $values[] = $translatedValue;
        }

        for ($i = 0; $i < count($properties); $i++) {
            $output[] = array(
                'property' => $properties[$i],
                'value' => $values[$i],
            );
        }

        return $output;
    }

    private function getPropertyDefinition($class, $property)
    {
        $definition = &$this->schema['classes'][$class][$property];

        if ($definition === null) {
            throw TranslatorException::unknownProperty($class, $property);
        }

        $type = reset($definition);
        $path = array_slice($definition, 1);

        return array($type, $path);
    }

    private function getListDefinition($list)
    {
        $definition = &$this->schema['lists'][$list];

        if ($definition === null) {
            throw TranslatorException::unknownList($list);
        }

        return $definition;
    }

    private function getConnectionDefinition($table, $connection)
    {
        $definition = &$this->schema['connections'][$table][$connection];

        if ($definition === null) {
            throw TranslatorException::unknownConnection($table, $connection);
        }

        return $definition;
    }

    private function getValueDefinition($table, $value)
    {
        $definition = &$this->schema['values'][$table][$value];

        if ($definition === null) {
            throw TranslatorException::unknownValue($table, $value);
        }

        return $definition;
    }

    private static function stringToPropertyList($string)
    {
        // TODO: makes an assumption about the format of the Parser's output
        return array_map(
            function ($property) {
                return array(Parser::TYPE_PROPERTY, $property);
            },
            explode('.', $string)
        );
    }

    private static function scanFunction($token, &$function, &$arguments)
    {
        $function = $token[1];
        $arguments = array_slice($token, 2);
    }
}
