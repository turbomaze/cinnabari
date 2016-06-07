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
        // TODO: throw exception
        $definition = &$this->schema['classes'][$class][$property];

        if ($definition === null) {
            return null;
        }

        $type = reset($definition);
        $path = array_slice($definition, 1);

        return array($type, $path);
    }

    public function getListDefinition($list)
    {
        // TODO: throw exception
        $definition = &$this->schema['lists'][$list];

        if ($definition === null) {
            return null;
        }

        // array($table, $expression, $hasZero)
        return $definition;
    }

    public function getValueDefinition($tableIdentifier, $value)
    {
        // TODO: throw exception
        $definition = &$this->schema['values'][$tableIdentifier][$value];

        if ($definition === null) {
            return null;
        }

        // array($expression, $hasZero)
        return $definition;
    }

    public function getConnectionDefinition($tableIdentifier, $connection)
    {
        // TODO: throw exception
        $definition = &$this->schema['connections'][$tableIdentifier][$connection];

        return $definition;
    }

    public function getId($table)
    {
        return null;
    }
}
