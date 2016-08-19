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
    private $argumentTypes;

    /** @var array */
    private $types;

    /** @var array */
    private $output;

    /** @var array */
    private static $typeCheckingFunctions = array(
        Output::TYPE_NULL => 'is_null(:0)',
        Output::TYPE_BOOLEAN => 'is_bool(:0)',
        Output::TYPE_INTEGER => 'is_integer(:0)',
        Output::TYPE_FLOAT => 'is_float(:0)',
        Output::TYPE_STRING => 'is_string(:0)'
    );

    public function __construct($types)
    {
        $this->argumentTypes = array();
        $this->types = $types;
        $this->output = array();
    }

    public function useArgument($name, $hasZero)
    {
        $input = self::getInputPhp($name);
        $id = $this->insertParameter($input);

        $this->argumentTypes[$name] = $hasZero;
        return $id;
    }

    public function useIncrementedArgument($name, $hasZero)
    {
        $input = self::getInputPhp($name);
        $id = $this->insertParameter("{$input} + 1");

        $this->argumentTypes[$name] = $hasZero;

        return $id;
    }

    public function useSubtractiveArgument($nameA, $nameB, $hasZero)
    {
        $inputA = self::getInputPhp($nameA);
        $inputB = self::getInputPhp($nameB);
        $idB = $this->insertParameter("{$inputB} - {$inputA}");

        $this->argumentTypes[$nameA] = $hasZero;
        $this->argumentTypes[$nameB] = $hasZero;

        return $idB;
    }

    public function getPhp()
    {
        $statements = array_flip($this->output);
        $array = self::getArray($statements);
        $assignment = self::getAssignment('$output', $array);
        if (count($this->argumentTypes) > 0) {
            $guardedAssignment = $this->getGuardedAssignment($assignment);
            return $guardedAssignment;
        } else {
            return $assignment;
        }
    }

    private function getGuardedAssignment($body)
    {
        $nullAssignment = self::getAssignment('$output', 'null');
        $typeChecks = self::getTypeChecks(
            $this->types['ordering'],
            $this->types['hierarchy']
        );
        return self::getIfElse($typeChecks, $body, $nullAssignment);
    }

    private function getTypeChecks($names, $hierarchy)
    {
        $rootName = $names[0];
        $nullCheck = self::getSingleTypeCheck($rootName, Output::TYPE_NULL);

        if (reset($hierarchy) === true) {
            # if this is the last layer of checks
            $hierarchyChecks = array();
            if ($this->argumentTypes[$rootName] === true) {
                $hierarchyChecks[] = $nullCheck;
            }
            foreach ($hierarchy as $type => $value) {
                $hierarchyChecks[] = self::getSingleTypeCheck($rootName, $type);
            }
            if (count($hierarchyChecks) > 1) {
                return self::group(self::getOr($hierarchyChecks));
            } else {
                return self::getOr($hierarchyChecks);
            }
        } else {
            # there are nested checks within this, so recurse
            $typeChecks = array();
            foreach ($hierarchy as $rootType => $subhierarchy) {
                $typeCheck = self::getSingleTypeCheck($rootName, $rootType);
                if ($this->argumentTypes[$rootName] === true) {
                    $typeCheck = self::group(
                        "\n" . self::indent(self::getOr(array($nullCheck, $typeCheck))) . "\n"
                    );
                }
                $conditional = self::getTypeChecks(array_slice($names, 1), $subhierarchy);
                $typeChecks[] = self::group(
                    "\n" . self::indent(self::getAnd(array($typeCheck, $conditional))) . "\n"
                );
            }
            if (count($typeChecks) > 1) {
                return self::group(
                    "\n" . self::indent(self::getOr($typeChecks)) . "\n"
                );
            } else {
                return self::getOr($typeChecks);
            }
        }
    }

    private static function getSingleTypeCheck($name, $type)
    {
        $placeholder = ':0';
        $input = self::getInputPhp($name);
        return str_replace($placeholder, $input, self::$typeCheckingFunctions[$type]);
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

    protected static function getIfElse($conditional, $body, $else)
    {
        $php = self::getIf($conditional, $body);
        $indentedElse = self::indent($else);
        $php .= " else {\n{$indentedElse}\n}";
        return $php;
    }

    protected static function getIf($conditional, $body)
    {
        $indentedBody = self::indent($body);
        if (self::isGrouped($conditional)) {
            return "if {$conditional} {\n{$indentedBody}\n}";
        } else {
            return "if ({$conditional}) {\n{$indentedBody}\n}";
        }
    }

    protected static function isGrouped($input)
    {
        if ($input[0] !== '(') {
            return false;
        }
        $parentheses = preg_replace('/[^()]/', '', $input);
        $count = 0;
        for ($i = 1; $i < count($parentheses) - 1; $i++) {
            $count += ($parentheses[$i] === '(') ? 1 : -1;
            if ($count < 0) {
                return false;
            }
        }
        // assumes well-balanced parentheses to begin with
        return $count === 0;
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
