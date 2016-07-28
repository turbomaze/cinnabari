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

use Datto\Cinnabari\ArgumentsException;

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

    public function useArgument($name, $neededType)
    {
        if (!array_key_exists($name, $this->input)) {
            throw ArgumentsException::inputNotProvided($name, $neededType);
        }

        $userType = gettype($this->input[$name]);

        if (($userType !== 'NULL') && ($userType !== $neededType)) {
            throw ArgumentsException::wrongInputType($name, $userType, $neededType);
        }

        $input = self::getInputPhp($name);
        $id = $this->insertParameter($input);

        return $id;
    }

    /**
     * TODO: this function currently inserts *two* expressions (the minuend is
     * implicitly inserted along with the subtraction expression as a whole).
     * To insert two independent expressions, we should use two independent
     * function calls (e.g. useArgument(...); $useSubtractiveArgument(...);)
     */
    public function useSubtractiveArgument($nameA, $nameB, $neededTypeA, $neededTypeB)
    {
        if (!array_key_exists($nameA, $this->input) || !array_key_exists($nameB, $this->input)) {
            return null;
        }

        $userTypeA = gettype($this->input[$nameA]);

        if (($userTypeA !== 'NULL') && ($userTypeA !== $neededTypeA)) {
            return null;
        }

        $userTypeB = gettype($this->input[$nameB]);

        if (($userTypeB !== 'NULL') && ($userTypeB !== $neededTypeB)) {
            return null;
        }

        $inputA = self::getInputPhp($nameA);
        $idA = $this->insertParameter($inputA);

        $inputB = self::getInputPhp($nameB);
        $idB = $this->insertParameter("{$inputB} - {$inputA}");

        return array($idA, $idB);
    }

    public function getPhp()
    {
        $statements = array_flip($this->output);
        $array = self::getArray($statements);
        return self::getAssignment('$output', $array);
    }

    private function insertParameter($inputString)
    {
        $id = &$this->output[$inputString];
        if ($id === null) {
            $id = count($this->output) - 1;
        }
        return $id;
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

    private static function getInputPhp($parameter)
    {
        $parameterName = var_export($parameter, true);
        return "\$input[{$parameterName}]";
    }
}
