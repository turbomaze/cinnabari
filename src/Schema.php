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
            throw new Exception(
                Exception::ERROR_CLASS_DNE,
                array(
                    'class' => $class
                ),
                Exception::ERROR_CLASS_DNE . " Error: class '{$class}' does not exist."
            );
        }
        
        if ($propertyDefinition === null) {
            throw new Exception(
                Exception::ERROR_PROPERTY_DNE,
                array(
                    'class' => $class,
                    'property' => $property
                ),
                Exception::ERROR_PROPERTY_DNE . " Error: property '{$property}' " .
                " of class '{$class}' does not exist."
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
            throw new Exception(
                Exception::ERROR_LIST_DNE,
                array(
                    'list' => $list
                ),
                Exception::ERROR_LIST_DNE . " Error: list '{$list}' does not exist."
            );
        }

        // array($table, $expression, $hasZero)
        return $definition;
    }

    public function getValueDefinition($tableIdentifier, $value)
    {
        $definition = &$this->schema['values'][$tableIdentifier][$value];

        if ($definition === null) {
            throw new Exception(
                Exception::ERROR_VALUE_DNE,
                array(
                    'tableIdentifier' => $tableIdentifier,
                    'value' => $value
                ),
                Exception::ERROR_VALUE_DNE . " Error: value '{$value}' in table " .
                "'{$tableIdentifier}' does not exist."
            );
        }

        // array($expression, $hasZero)
        return $definition;
    }

    public function getConnectionDefinition($tableIdentifier, $connection)
    {
        $definition = &$this->schema['connections'][$tableIdentifier][$connection];

        if ($definition === null) {
            throw new Exception(
                Exception::ERROR_CONNECTION_DNE,
                array(
                    'tableIdentifier' => $tableIdentifier,
                    'connection' => $connection
                ),
                Exception::ERROR_CONNECTION_DNE .
                " Error: connection '{$tableIdentifier}->{$connection}' does not exist."
            );
        }

        return $definition;
    }

    public function getId($table)
    {
        return null;
    }
}
