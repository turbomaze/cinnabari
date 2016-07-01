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

class Schema
{
    // schema errors
    const ERROR_NO_CLASS = 101;
    const ERROR_NO_PROPERTY = 102;
    const ERROR_NO_LIST = 103;
    const ERROR_NO_VALUE = 104;
    const ERROR_NO_CONNECTION = 105;

    /** @var array */
    private $schema;

    public function __construct($schema)
    {
        $this->schema = $schema;
    }

    public function getPropertyDefinition($class, $property)
    {
        $classDefinition = &$this->schema['classes'][$class];
        $propertyDefinition = &$this->schema['classes'][$class][$property];

        if ($classDefinition === null) {
            $classString = json_encode($class);
            throw new Exception(
                self::ERROR_NO_CLASS,
                array(
                    'class' => $class
                ),
                "class {$classString} does not exist."
            );
        }
        
        if ($propertyDefinition === null) {
            $propertyString = json_encode($property);
            $classString = json_encode($class);
            throw new Exception(
                self::ERROR_NO_PROPERTY,
                array(
                    'class' => $class,
                    'property' => $property
                ),
                "property {$propertyString} of class {$classString} does not exist."
            );
        }

        $type = reset($propertyDefinition);
        $path = array_slice($propertyDefinition, 1);

        return array($type, $path);
    }

    public function getListDefinition($list)
    {
        $definition = &$this->schema['lists'][$list];

        if ($definition === null) {
            $listString = json_encode($list);
            throw new Exception(
                self::ERROR_NO_LIST,
                array(
                    'list' => $list
                ),
                "list {$listString} does not exist."
            );
        }

        // array($table, $expression, $hasZero)
        return $definition;
    }

    public function getValueDefinition($tableIdentifier, $value)
    {
        $definition = &$this->schema['values'][$tableIdentifier][$value];

        if ($definition === null) {
            $valueString = json_encode($value);
            $tableString = json_encode($tableIdentifier);
            throw new Exception(
                self::ERROR_NO_VALUE,
                array(
                    'tableIdentifier' => $tableIdentifier,
                    'value' => $value
                ),
                "value {$valueString} in table {$tableString} does not exist."
            );
        }

        // array($expression, $hasZero)
        return $definition;
    }

    public function getConnectionDefinition($tableIdentifier, $connection)
    {
        $definition = &$this->schema['connections'][$tableIdentifier][$connection];

        if ($definition === null) {
            $connectionString = json_encode($tableIdentifier) . '->' . json_encode($connection);
            throw new Exception(
                self::ERROR_NO_CONNECTION,
                array(
                    'tableIdentifier' => $tableIdentifier,
                    'connection' => $connection
                ),
                "{$connectionString} does not exist."
            );
        }

        return $definition;
    }

    public function getId($table)
    {
        return null;
    }
}
