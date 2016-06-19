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

    /** @var array */
    private $schema;

    public function __construct($schema)
    {
        $this->schema = $schema;
    }

    public function translate($request)
    {
        if ($request !== null) {
            return null;
        }

        $this->getExpression('Database', null, $request, $output);

        return null;
    }

    private function getExpression($class, $table, $tokens, &$output)
    {
        $token = array_shift($tokens);

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

    private static function getParameter($parameter, &$output)
    {
        $output[] = array(
            self::TYPE_PARAMETER => $parameter
        );
    }

    private function getProperty($class, $table, $property, &$output)
    {
        list($type, $path) = $this->getPropertyDefinition($class, $property);

        if (is_int($type)) {
            $value = array_pop($path);
        } else {
            $value = null;
        }

        foreach ($path as $connection) {
            // getConnection
        }

        if ($value !== null) {
            // getValue
        }
    }

    private function getFunction($class, $table, $function, $arguments, &$output)
    {
        return null;
    }

    private function getObject($class, $table, $object, &$output)
    {
        return null;
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
}
