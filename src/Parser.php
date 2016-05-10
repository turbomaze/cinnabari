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

class Parser
{
    // Token types
    const TYPE_PARAMETER = 1;
    const TYPE_PROPERTY = 2;
    const TYPE_FUNCTION = 3;
    const TYPE_OBJECT = 4;
    const TYPE_PATH = 5;

    // Operator Arity
    const UNARY = 1;
    const BINARY = 2;

    // Operator Associativity
    const NON_ASSOCIATIVE = 0;
    const LEFT_ASSOCIATIVE = 1;
    const RIGHT_ASSOCIATIVE = 2;

    private static $operators = array(
        '.' => array(
            'name' => 'dot',
            'precedence' => 7,
            'arity' => self::BINARY,
            'associativity' => self::LEFT_ASSOCIATIVE
        ),
        '*' => array(
            'name' => 'times',
            'precedence' => 6,
            'arity' => self::BINARY,
            'associativity' => self::LEFT_ASSOCIATIVE
        ),
        '/' => array(
            'name' => 'divides',
            'precedence' => 6,
            'arity' => self::BINARY,
            'associativity' => self::LEFT_ASSOCIATIVE
        ),
        '+' => array(
            'name' => 'plus',
            'precedence' => 5,
            'arity' => self::BINARY,
            'associativity' => self::LEFT_ASSOCIATIVE
        ),
        '-' => array(
            'name' => 'minus',
            'precedence' => 5,
            'arity' => self::BINARY,
            'associativity' => self::LEFT_ASSOCIATIVE
        ),
        '<' => array(
            'name' => 'less',
            'precedence' => 4,
            'arity' => self::BINARY,
            'associativity' => self::NON_ASSOCIATIVE
        ),
        '<=' => array(
            'name' => 'lessEqual',
            'precedence' => 4,
            'arity' => self::BINARY,
            'associativity' => self::NON_ASSOCIATIVE
        ),
        '=' => array(
            'name' => 'equal',
            'precedence' => 4,
            'arity' => self::BINARY,
            'associativity' => self::NON_ASSOCIATIVE
        ),
        '!=' => array(
            'name' => 'notEqual',
            'precedence' => 4,
            'arity' => self::BINARY,
            'associativity' => self::NON_ASSOCIATIVE
        ),
        '>=' => array(
            'name' => 'greaterEqual',
            'precedence' => 4,
            'arity' => self::BINARY,
            'associativity' => self::NON_ASSOCIATIVE
        ),
        '>' => array(
            'name' => 'greater',
            'precedence' => 4,
            'arity' => self::BINARY,
            'associativity' => self::NON_ASSOCIATIVE
        ),
        'not' => array(
            'name' => 'not',
            'precedence' => 3,
            'arity' => self::UNARY,
            'associativity' => self::RIGHT_ASSOCIATIVE
        ),
        'and' => array(
            'name' => 'and',
            'precedence' => 2,
            'arity' => self::BINARY,
            'associativity' => self::LEFT_ASSOCIATIVE
        ),
        'or' => array(
            'name' => 'or',
            'precedence' => 1,
            'arity' => self::BINARY,
            'associativity' => self::LEFT_ASSOCIATIVE
        )
    );

    public function parse($tokens)
    {
        if (!is_array($tokens)) {
            return null;
        }

        return self::getExpression($tokens);
    }

    private static function getExpression($tokens)
    {
        $tokens = self::sortTokens($tokens);
        return self::getTokenStream($tokens);
    }

    private static function sortTokens($input)
    {
        if (count($input) <= 1) {
            return $input;
        }

        $operators = array();
        $output = array();

        foreach ($input as $token) {
            $type = key($token);

            if ($type === Lexer::TYPE_OPERATOR) {
                self::releaseOperators($token, $operators, $output);
                $operators[] = $token;
            } else {
                $output[] = $token;
            }
        }

        while (0 < count($operators)) {
            $output[] = array_pop($operators);
        }

        return $output;
    }

    private static function releaseOperators($token, &$tokens, &$output)
    {
        $operatorA = self::getOperator($token);

        $isLeftAssociativeA = $operatorA['associativity'] !== self::RIGHT_ASSOCIATIVE;
        $precedenceA = $operatorA['precedence'];

        for ($i = count($tokens) - 1; -1 < $i; --$i) {
            $operatorB = self::getOperator($tokens[$i]);
            $precedenceB = $operatorB['precedence'];

            if ($precedenceA < $precedenceB) {
                $output[] = array_pop($tokens);
            }

            if ($isLeftAssociativeA && ($precedenceA === $precedenceB)) {
                $output[] = array_pop($tokens);
            }
        }
    }

    private static function getOperator($token)
    {
        $lexeme = current($token);

        return @self::$operators[$lexeme];
    }

    private static function getTokenStream(&$tokens)
    {
        $token = array_pop($tokens);

        if (!is_array($token)) {
            return null;
        }

        list($type, $value) = each($token);

        switch ($type) {
            case Lexer::TYPE_PARAMETER:
                return self::getParameterExpression($value);

            case Lexer::TYPE_PROPERTY:
                return self::getPropertyExpression($value);

            case Lexer::TYPE_FUNCTION:
                return self::getFunctionExpression($value);

            case Lexer::TYPE_OBJECT:
                return self::getObjectExpression($value);

            case Lexer::TYPE_GROUP:
                return self::getExpression($value);

            case Lexer::TYPE_OPERATOR:
                return self::getOperatorExpression($value, $tokens);

            default:
                return null;
        }
    }

    private static function getParameterExpression($name)
    {
        return array(self::TYPE_PARAMETER, $name);
    }

    private static function getPropertyExpression($name)
    {
        return array(self::TYPE_PROPERTY, $name);
    }

    private static function getFunctionExpression($input)
    {
        $name = array_shift($input);

        $arguments = array();

        foreach ($input as $tokens) {
            $arguments[] = self::getExpression($tokens);
        }

        $value = $arguments;
        array_unshift($value, self::TYPE_FUNCTION, $name);

        return $value;
    }

    private static function getObjectExpression($input)
    {
        $output = array();

        foreach ($input as $property => $tokens) {
            $output[$property] = self::getExpression($tokens);
        }

        return array(self::TYPE_OBJECT, $output);
    }

    private static function getOperatorExpression($lexeme, &$tokens)
    {
        $operator = @self::$operators[$lexeme];
        $name = $operator['name'];

        // Binary operator
        if ($operator['arity'] === self::BINARY) {
            $childB = self::getTokenStream($tokens);
            $childA = self::getTokenStream($tokens);

            if ($name === 'dot') {
                return self::getPathExpression($childA, $childB);
            }

            return array(self::TYPE_FUNCTION, $name, $childA, $childB);
        }

        // Unary operator
        $child = self::getTokenStream($tokens);
        return array(self::TYPE_FUNCTION, $name, $child);
    }

    private static function getPathExpression($childA, $childB)
    {
        if ($childA[0] === self::TYPE_PATH) {
            $children = array_slice($childA, 1);
        } else {
            $children = array($childA);
        }

        if ($childB[0] === self::TYPE_PATH) {
            $children = array_merge($children, array_slice($childB, 1));
        } else {
            $children[] = $childB;
        }

        array_unshift($children, self::TYPE_PATH);
        return $children;
    }
}
