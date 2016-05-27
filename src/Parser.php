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
    const ASSOCIATIVITY_NONE = 0;
    const ASSOCIATIVITY_LEFT = 1;
    const ASSOCIATIVITY_RIGHT = 2;

    // Errors
    const ERROR_UNEXPECTED_INPUT = 1;

    private static $operators = array(
        '.' => array(
            'name' => 'dot',
            'precedence' => 7,
            'arity' => self::BINARY,
            'associativity' => self::ASSOCIATIVITY_LEFT
        ),
        '*' => array(
            'name' => 'times',
            'precedence' => 6,
            'arity' => self::BINARY,
            'associativity' => self::ASSOCIATIVITY_LEFT
        ),
        '/' => array(
            'name' => 'divides',
            'precedence' => 6,
            'arity' => self::BINARY,
            'associativity' => self::ASSOCIATIVITY_LEFT
        ),
        '+' => array(
            'name' => 'plus',
            'precedence' => 5,
            'arity' => self::BINARY,
            'associativity' => self::ASSOCIATIVITY_LEFT
        ),
        '-' => array(
            'name' => 'minus',
            'precedence' => 5,
            'arity' => self::BINARY,
            'associativity' => self::ASSOCIATIVITY_LEFT
        ),
        '<' => array(
            'name' => 'less',
            'precedence' => 4,
            'arity' => self::BINARY,
            'associativity' => self::ASSOCIATIVITY_NONE
        ),
        '<=' => array(
            'name' => 'lessEqual',
            'precedence' => 4,
            'arity' => self::BINARY,
            'associativity' => self::ASSOCIATIVITY_NONE
        ),
        '=' => array(
            'name' => 'equal',
            'precedence' => 4,
            'arity' => self::BINARY,
            'associativity' => self::ASSOCIATIVITY_NONE
        ),
        '!=' => array(
            'name' => 'notEqual',
            'precedence' => 4,
            'arity' => self::BINARY,
            'associativity' => self::ASSOCIATIVITY_NONE
        ),
        '>=' => array(
            'name' => 'greaterEqual',
            'precedence' => 4,
            'arity' => self::BINARY,
            'associativity' => self::ASSOCIATIVITY_NONE
        ),
        '>' => array(
            'name' => 'greater',
            'precedence' => 4,
            'arity' => self::BINARY,
            'associativity' => self::ASSOCIATIVITY_NONE
        ),
        'not' => array(
            'name' => 'not',
            'precedence' => 3,
            'arity' => self::UNARY,
            'associativity' => self::ASSOCIATIVITY_RIGHT
        ),
        'and' => array(
            'name' => 'and',
            'precedence' => 2,
            'arity' => self::BINARY,
            'associativity' => self::ASSOCIATIVITY_LEFT
        ),
        'or' => array(
            'name' => 'or',
            'precedence' => 1,
            'arity' => self::BINARY,
            'associativity' => self::ASSOCIATIVITY_LEFT
        )
    );

    public function parse($tokens)
    {
        return self::getExpression($tokens);
    }

    /*
    private static function validateTokens($tokens)
    {
        if (!is_array($tokens)) {
            self::errorUnexpectedValue();
        }

        $i = 0;

        if (!array_key_exists($i, $tokens)) {
            self::errorExpectedArrayKey(0);
        }

        foreach ($tokens as $key => $token) {
            if ($key !== $i++) {
                self::errorUnexpectedArrayKey($key);
            }

            try {
                self::validateToken($token);
            } catch (Exception $exception) {
                self::errorUnexpectedArrayValue($key, $exception->getData());
            }
        }
    }

    private static function validateToken($token)
    {
        if (!is_array($token)) {
            self::errorUnexpectedValue();
        }

        list($type, $value) = each($token);

        if ($type === null) {
            // TODO: what key should we use?
            self::errorExpectedArrayKey(null);
        }

        $name = self::getTypeName($type);

        if ($name === null) {
            self::errorUnexpectedArrayKey($type);
        }

        try {
            $validator = 'self::validate' . ucfirst($name);
            call_user_func($validator, $value);
        } catch (Exception $exception) {
            self::errorUnexpectedArrayValue($type, $exception->getData());
        }

        $key = key($token);

        if ($key !== null) {
            self::errorUnexpectedArrayKey($key);
        }
    }

    private static function getTypeName($type)
    {
        switch ($type) {
            case Lexer::TYPE_PARAMETER:
                return 'parameter';

            case Lexer::TYPE_PROPERTY:
                return 'property';

            case Lexer::TYPE_FUNCTION:
                return 'function';

            case Lexer::TYPE_OPERATOR:
                return 'operator';

            case Lexer::TYPE_OBJECT:
                return 'object';

            case Lexer::TYPE_GROUP:
                return 'group';

            default:
                return null;
        }
    }

    protected static function validateParameter($input)
    {
        if (!is_string($input)) {
            self::errorUnexpectedValue();
        }
    }

    protected static function validateProperty($input)
    {
        if (!is_string($input)) {
            self::errorUnexpectedValue();
        }
    }

    protected static function validateFunction($input)
    {
        if (!is_array($input)) {
            self::errorUnexpectedValue();
        }

        list($key, $value) = each($input);

        if ($key === null) {
            self::errorExpectedArrayKey(0);
        }

        if ($key !== 0) {
            self::errorUnexpectedArrayKey($key);
        }

        if (!is_string($value)) {
            self::errorUnexpectedArrayValue($key, null);
        }

        unset($input[$key]);

        $i = 1;

        foreach ($input as $key => $value) {
            if ($key !== $i++) {
                self::errorUnexpectedArrayKey($key);
            }

            try {
                self::validateTokens($value);
            } catch (Exception $exception) {
                self::errorUnexpectedArrayValue($key, $exception->getData());
            }
        }
    }

    protected static function validateOperator($input)
    {
        if (!is_string($input)) {
            self::errorUnexpectedValue();
        }
    }

    protected static function validateObject($object)
    {
        if (!is_array($object)) {
            self::errorUnexpectedValue();
        }
    }

    protected static function validateGroup($group)
    {
        // TODO
        try {
            self::validateTokens($group);
        } catch (Exception $exception) {
            $code = $exception->getCode();
            $data = $exception->getData();
            $data[] = null;

            throw new Exception($code, $data);
        }
    }

    private static function errorUnexpectedValue()
    {
        throw new Exception(self::ERROR_UNEXPECTED_INPUT, null);
    }

    private static function errorExpectedArrayKey($key)
    {
        throw new Exception(self::ERROR_UNEXPECTED_INPUT, array(false, null, null));
    }

    private static function errorUnexpectedArrayKey($key)
    {
        throw new Exception(self::ERROR_UNEXPECTED_INPUT, array(false, $key, null));
    }

    private static function errorUnexpectedArrayValue($key, $position)
    {
        throw new Exception(self::ERROR_UNEXPECTED_INPUT, array(true, $key, $position));
    }
    */

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

        $isLeftAssociativeA = $operatorA['associativity'] !== self::ASSOCIATIVITY_RIGHT;
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

            default: // Lexer::TYPE_OPERATOR:
                return self::getOperatorExpression($value, $tokens);
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
