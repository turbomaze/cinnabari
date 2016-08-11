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

namespace Datto\Cinnabari\Php;

class Input
{
    /** @var array */
    private $input;

    /** @var array */
    private $argumentTypes;

    /** @var array */
    private $output;

    /** @var array */
    private static $typeCheckingFunctions = array(
        'null' => 'is_null',
        'boolean' => 'is_bool',
        'integer' => 'is_integer',
        'float' => 'is_float',
        'string' => 'is_string'
    );

    public function __construct()
    {
        $this->argumentTypes = array();
        $this->output = array();
    }

    public function useArgument($name)
    {
        $input = self::getInputPhp($name);
        $id = $this->insertParameter($input);

        return $id;
    }

    public function useSubtractiveArgument($nameA, $nameB)
    {
        if (!array_key_exists($nameA, $this->input) || !array_key_exists($nameB, $this->input)) {
            return null;
        }

        $inputA = self::getInputPhp($nameA);
        $inputB = self::getInputPhp($nameB);
        $idB = $this->insertParameter("{$inputB} - {$inputA}");

        return $idB;
    }

    public function setArgumentTypes($types)
    {
        $this->argumentTypes = $types;
    }

    public function getPhp()
    {
        $statements = array_flip($this->output);
        $array = self::getArray($statements);
        $assignment = self::getAssignment('$output', $array);
        if (count($this->argumentTypes) > 0) {
            $initialAssignment = self::getAssignment('$output', 'null');
            $typeChecks = $this->getTypeCheck($assignment);
            return $initialAssignment . "\n" . $typeChecks;
        } else {
            return $assignment;
        }
    }

    private function getTypeCheck($body)
    {
        $checks = array();
        foreach ($this->argumentTypes as $key => $restriction) {
            $input = self::getInputPhp($restriction['name']);
            $type = $restriction['type'];
            $typeCheck = (
                self::$typeCheckingFunctions[$type] . "({$input})"
            );
            if ($restriction['hasZero']) {
                $nullCheck = self::$typeCheckingFunctions['null'] . "({$input})";
                $checks[] = self::group(
                    self::getOr(array($typeCheck, $nullCheck))
                );
            } else {
                $checks[] = $typeCheck;
            }
        }
        $conditional = self::negate(self::group(self::getAnd($checks)));
        $ifStatement = self::getIf($conditional, $body);
        return $ifStatement;
    }

    private function insertParameter($inputString)
    {
        $id = &$this->output[$inputString];
        if ($id === null) {
            $id = count($this->output) - 1;
        }
        return $id;
    }

    protected static function negate($expression)
    {
        return "!{$expression}";
    }

    protected static function group($expression)
    {
        return "({$expression})";
    }

    protected static function getAnd($expressions)
    {
        return self::getBinaryOperatorChain($expressions, '&&');
    }

    protected static function getOr($expressions)
    {
        return self::getBinaryOperatorChain($expressions, '||');
    }

    protected static function getBinaryOperatorChain($expressions, $operator)
    {
        return implode(" {$operator} ", $expressions);
    }

    protected static function getIf($conditional, $body)
    {
        $indentedBody = self::indent($body);
        return "if ({$conditional}) {\n{$indentedBody}\n}";
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

    private static function indent($string)
    {
        return "\t" . preg_replace('~\n(?!\n)~', "\n\t", $string);
    }
}
