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

use Datto\Cinnabari\Exception\LexerException;

/**
 * Class Lexer
 * @package Datto\Cinnabari
 *
 * EBNF:
 *
 * expression = binary-expression | unary-expression | object | group | function | property | parameter;
 * binary-expression = expression, space, binary-operator, space, expression;
 * space = { whitespace };
 * whitespace = ? any character matching the "\s" regular expression ?;
 * binary-operator = "." | "+" | "-" | "*" | "/" | "<=" | "<" | "!=" | "=" | ">=" | ">" | "and" | "or";
 * unary-expression = unary-operator, space, expression;
 * unary-operator = "not";
 * object = "{", space, pairs, space, "}";
 * pairs = pair, { space, ",", space, pair };
 * pair = json-string, space, ":", space, expression;
 * json-string = ? any JSON string (including the enclosing quotation marks) ?;
 * group = "(", space, expression, space, ")";
 * function = identifier, space, "(", space, [ arguments ], space, ")";
 * identifier = character, { character };
 * character = "a" | "b" | "c" | "d" | "e" | "f" | "g" | "h" | "i" | "j" | "k" | "l" | "m" | "n" | "o" |
 *     "p" | "q" | "r" | "s" | "t" | "u" | "v" | "w" | "x" | "y" | "z" | "A" | "B" | "C" | "D" |
 *     "E" | "F" | "G" | "H" | "I" | "J" | "K" | "L" | "M" | "N" | "O" | "P" | "Q" | "R" | "S" |
 *     "T" | "U" | "V" | "W" | "X" | "Y" | "Z" | "_" | "0" | "1" | "2" | "3" | "4" | "5" | "6" |
 *     "7" | "8" | "9";
 * arguments = expression, { space, ",", space, expression };
 * property = identifier;
 * parameter = ":", identifier;
 */

class Lexer
{
    const TYPE_PARAMETER = 1;
    const TYPE_PROPERTY = 2;
    const TYPE_FUNCTION = 3;
    const TYPE_OBJECT = 4;
    const TYPE_GROUP = 5;
    const TYPE_OPERATOR = 6;

    /**
     * @param string $input
     * @return array
     * @throws LexerException
     */
    public function tokenize($input)
    {
        if (!is_string($input)) {
            throw LexerException::invalidType($input);
        }

        $inputOriginal = $input;

        if (!self::getExpression($input, $output) || ($input !== false)) {
            $position = strlen($inputOriginal) - strlen($input);
            throw LexerException::syntaxError($inputOriginal, $position);
        }

        return $output;
    }

    private static function getExpression(&$input, &$output)
    {
        return (
            self::getUnaryOperation($input, $output)
            || self::getParameter($input, $output)
            || self::getPropertyOrFunction($input, $output)
            || self::getGroup($input, $output)
            || self::getObject($input, $output)
        ) && (
            !self::getBinaryOperator($input, $output)
            || self::getExpression($input, $output)
        );
    }

    private static function getUnaryOperation(&$input, &$output)
    {
        return self::getUnaryOperator($input, $output)
            && self::getExpression($input, $output);
    }

    private static function getUnaryOperator(&$input, &$output)
    {
        if (self::scan('(not)\s*', $input, $matches)) {
            $output[] = array(self::TYPE_OPERATOR => $matches[1]);
            return true;
        }

        return false;
    }

    private static function getParameter(&$input, &$output)
    {
        if (self::scan(':([a-zA-Z_0-9]+)', $input, $matches)) {
            $output[] = array(self::TYPE_PARAMETER => $matches[1]);
            return true;
        }

        return false;
    }

    private static function getPropertyOrFunction(&$input, &$output)
    {
        if (!self::getIdentifierName($input, $name)) {
            return false;
        }

        if (!self::scan('\s*\(\s*', $input)) {
            $output[] = array(self::TYPE_PROPERTY => $name);
            return true;
        }

        $value = array($name);

        if (self::getExpression($input, $argument)) {
            $value[] = $argument;

            while (self::scan('\s*,\s*', $input)) {
                if (!self::getExpression($input, $value[])) {
                    return false;
                }
            }
        }

        if (!self::scan('\s*\)', $input)) {
            return false;
        }

        $output[] = array(self::TYPE_FUNCTION => $value);
        return true;
    }

    private static function getIdentifierName(&$input, &$output)
    {
        if (self::scan('[a-zA-Z_0-9]+', $input, $matches)) {
            $output = $matches[0];
            return true;
        }

        return false;
    }

    private static function getGroup(&$input, &$output)
    {
        if (
            self::scan('\(\s*', $input)
            && self::getExpression($input, $expression)
            && self::scan('\s*\)', $input)
        ) {
            $output[] = array(self::TYPE_GROUP => $expression);
            return true;
        }

        return false;
    }

    private static function getObject(&$input, &$output)
    {
        if (!self::scan('{\s*', $input)) {
            return false;
        }

        $properties = array();

        if (!self::getProperty($input, $properties)) {
            return false;
        }

        while (self::scan('\s*,\s*', $input)) {
            if (!self::getProperty($input, $properties)) {
                return false;
            }
        }

        if (!self::scan('\s*}', $input)) {
            return false;
        }

        $output[] = array(self::TYPE_OBJECT => $properties);
        return true;
    }

    private static function getProperty(&$input, &$output)
    {
        return self::getString($input, $key)
            && self::scan('\s*:\s*', $input)
            && self::clear($output[$key])
            && self::getExpression($input, $output[$key]);
    }

    private static function getString(&$input, &$output)
    {
        $expression = '\\"(?:[^"\\x00-\\x1f\\\\]|\\\\(?:["\\\\/bfnrt]|u[0-9a-f]{4}))*\\"';

        if (self::scan($expression, $input, $matches)) {
            $output = json_decode($matches[0], true);
            return true;
        }

        return false;
    }

    private static function clear(&$value)
    {
        $value = null;

        return true;
    }

    private static function getBinaryOperator(&$input, &$output)
    {
        if (self::scan('\s*([-+*/.]|and|or|<=|<|!=|=|>=|>)\s*', $input, $matches)) {
            $output[] = array(self::TYPE_OPERATOR => $matches[1]);
            return true;
        }

        return false;
    }

    private static function scan($expression, &$input, &$output = null)
    {
        $delimiter = "\x03";
        $flags = 'A'; // A: anchored

        $pattern = "{$delimiter}{$expression}{$delimiter}{$flags}";

        if (preg_match($pattern, $input, $matches) !== 1) {
            return false;
        }

        $length = strlen($matches[0]);
        $input = substr($input, $length);
        $output = $matches;

        return true;
    }
}
