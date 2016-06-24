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

use Datto\Cinnabari\Php\Output;

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
            return null;
        }

        $userType = gettype($this->input[$name]);

        if (($userType !== 'NULL') && ($userType !== $neededType)) {
            return null;
        }

        $safeName = var_export($name, true);
        $input = self::getParameterPhp($safeName);
        $id = $this->insertParameter($input);

        return $id;
    }

    public function useSubtractiveArgument($nameA, $nameB, $neededTypeA, $neededTypeB)
    {
        if (!array_key_exists($nameA, $this->input) || !array_key_exists($nameB, $this->input)) {
            return null;
        }

        $userTypeA = gettype($this->input[$nameA]);
        $userTypeB = gettype($this->input[$nameB]);

        if (($userTypeA !== 'NULL') && ($userTypeA !== $neededTypeA)) {
            return null;
        }

        if (($userTypeB !== 'NULL') && ($userTypeB !== $neededTypeB)) {
            return null;
        }

        $safeNameA = var_export($nameA, true);
        $safeNameB = var_export($nameB, true);
        $inputA = self::getParameterPhp($safeNameA);
        $inputB = self::getParameterPhp($safeNameB);
        $idA = $this->insertParameter($inputA);
        $idB = $this->insertParameter($inputB . ' - ' .  $inputA);

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

    protected static function getParameterPhp($parameterName)
    {
        return "\$input[{$parameterName}]";
    }
}
