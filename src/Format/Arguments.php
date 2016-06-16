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

use Datto\Cinnabari\Mysql\Expression\Parameter;

class Arguments
{
    /** @var array */
    private $input;

    /** @var array */
    private $output;

    /** @var array */
    private $neededTypes;

    private static $typeCheckingFunctions = array(
        'integer' => 'is_integer',
        'float' => 'is_float',
        'bool' => 'is_bool',
        'string' => 'is_string'
    );

    public function __construct($input)
    {
        $this->input = $input;
        $this->output = array();
    }

    public function useArgument($name, $neededType, &$parameter)
    {
        if (!array_key_exists($name, $this->input)) {
            return null;
        }

        $userType = gettype($this->input[$name]);

        // bad type
        if (($userType !== 'NULL') && ($userType !== $neededType)) {
            return null;
        }

        // get its id
        $id = &$this->output[$name];

        if ($id === null) {
            $id = count($this->output) - 1;
        }

        // create the parameter object and store the required type information
        $parameter = new Parameter($id);
        $this->neededTypes[$name] = array($neededType, $parameter);

        return $id;
    }

    public function getPhp()
    {
        $statements = array_map('self::getInputStatement', array_flip($this->output));
        $array = self::getArray($statements);
        $assignment = self::getAssignment('$output', $array);

        return self::surroundWithTypeCheckIf($assignment, $this->neededTypes);
    }

    protected static function surroundWithTypeCheckIf($inner, $requiredTypes)
    {
        if (count($requiredTypes) === 0) {
            return $inner;
        }

        $statement = 'if (' . self::getTypeCheckConstraints($requiredTypes) . ") {\n";
        $statement .= "\t" . preg_replace('/\\n/', "\n\t", $inner) . "\n";
        $statement .= "}";
        return $statement;
    }

    protected static function getTypeCheckConstraints($requiredTypes)
    {
        $checks = array();
        foreach ($requiredTypes as $name => $typeInfo) {
            $arrayKeyExists = "array_key_exists('{$name}', \$input)";
            $typeCheck = self::$typeCheckingFunctions[$typeInfo[0]] . "(\$input['{$name}'])";
            if ($typeInfo[1]->nullable) {
                $typeCheck = "(\$input['{$name}'] === null || " . $typeCheck . ')';
            }
            $checks[] = "\n\t(" . $arrayKeyExists . ' && ' . $typeCheck . ')';
        }
        return join(" && ", $checks) . "\n";
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
