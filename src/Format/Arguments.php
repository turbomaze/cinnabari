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

namespace Datto\Cinnabari\Format;

class Arguments
{
    /** @var array */
    private $input;

    /** @var array */
    private $output;

    public function __construct($input)
    {
        $this->input = $input;
        $this->output = array();
    }

    public function useArgument($name, $type)
    {
        if (
            !array_key_exists($name, $this->input) ||
            (gettype($this->input[$name]) !== $type)
        ) {
            return null;
        }

        $id = &$this->output[$name];

        if ($id === null) {
            $id = count($this->output) - 1;
        }

        return $id;
    }

    public function getPhp()
    {
        $statements = array_map('self::getInputStatement', array_flip($this->output));
        $array = self::getArray($statements);
        return self::getAssignment('$output', $array);
    }

    protected static function getInputStatement($key)
    {
        $name = var_export($key, true);
        return "\$input[{$name}]";
    }

    protected static function getArray($statements)
    {
        if (count($statements) === 0) {
            return 'array()';
        }

        $statementList = implode(",\n\t", $statements);
        return "array(\n\t{$statementList}\n)";
    }

    protected static function getAssignment($variable, $value)
    {
        return "{$variable} = {$value};";
    }
}
