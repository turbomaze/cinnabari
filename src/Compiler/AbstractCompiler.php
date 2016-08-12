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

namespace Datto\Cinnabari\Compiler;

use Datto\Cinnabari\Exception\CompilerException;
use Datto\Cinnabari\Mysql\AbstractMysql;
use Datto\Cinnabari\Mysql\Expression\AbstractExpression;
use Datto\Cinnabari\Mysql\Expression\OperatorAnd;
use Datto\Cinnabari\Mysql\Expression\OperatorDivides;
use Datto\Cinnabari\Mysql\Expression\OperatorEqual;
use Datto\Cinnabari\Mysql\Expression\OperatorGreater;
use Datto\Cinnabari\Mysql\Expression\OperatorGreaterEqual;
use Datto\Cinnabari\Mysql\Expression\OperatorLess;
use Datto\Cinnabari\Mysql\Expression\OperatorLessEqual;
use Datto\Cinnabari\Mysql\Expression\OperatorMinus;
use Datto\Cinnabari\Mysql\Expression\OperatorNot;
use Datto\Cinnabari\Mysql\Expression\OperatorOr;
use Datto\Cinnabari\Mysql\Expression\OperatorPlus;
use Datto\Cinnabari\Mysql\Expression\OperatorRegexpBinary;
use Datto\Cinnabari\Mysql\Expression\OperatorTimes;
use Datto\Cinnabari\Mysql\Expression\Parameter;
use Datto\Cinnabari\Php\Input;
use Datto\Cinnabari\Php\Output;
use Datto\Cinnabari\Translator;

/**
 * Class AbstractCompiler
 * @package Datto\Cinnabari
 */
abstract class AbstractCompiler implements CompilerInterface
{
    /** @var array */
    protected $request;

    /** @var Input */
    protected $input;

    /** @var int */
    protected $context;
    
    /** @var AbstractMysql */
    protected $mysql;

    /** @var array */
    protected $rollbackPoint;

    protected static $REQUIRED = false;
    protected static $OPTIONAL = true;

    public function __construct()
    {
        $this->rollbackPoint = array();
    }

    /**
     * @param array $token
     * @param string $neededType
     * @param AbstractExpression|null $output
     */
    abstract protected function getProperty($token, $neededType, &$output);

    protected function getOptionalFilterFunction()
    {
        if (!self::scanFunction(reset($this->request), $name, $arguments)) {
            return false;
        }

        if ($name !== 'filter') {
            return false;
        }

        // at this point, we're sure they want to filter
        if (!isset($arguments) || (count($arguments) === 0)) {
            throw CompilerException::noFilterArguments($this->request);
        }

        // TODO: throw exception for bad filters
        if (!$this->getBooleanExpression($arguments[0], self::$REQUIRED, $where)) {
            throw CompilerException::badFilterExpression(
                $this->context,
                $arguments[0]
            );
        }

        $this->mysql->setWhere($where);

        array_shift($this->request);

        return true;
    }

    protected function handleJoin($token)
    {
        $this->context = $this->mysql->addJoin(
            $this->context,
            $token['tableB'],
            $token['expression'],
            $token['hasZero'],
            $token['hasMany']
        );
    }

    protected function followJoins($arrayToken)
    {
        // consume all of the joins
        while ($this->scanJoin(reset($arrayToken), $joinToken)) {
            $this->handleJoin($joinToken);
            array_shift($arrayToken);
        }
        return $arrayToken;
    }

    protected function getExpression($arrayToken, $hasZero, &$expression, &$type)
    {
        if ($this->getBooleanExpression($arrayToken, $hasZero, $expression)) {
            $type = Output::TYPE_BOOLEAN;
        } else if ($this->getIntegerExpression($arrayToken, $hasZero, $expression)) {
            $type = Output::TYPE_INTEGER;
        } else if ($this->getFloatExpression($arrayToken, $hasZero, $expression)) {
            $type = Output::TYPE_FLOAT;
        } else if ($this->getStringExpression($arrayToken, $hasZero, $expression)) {
            $type = Output::TYPE_STRING;
        } else {
            return false;
        }

        return true;
    }

    private function getBooleanExpression($arrayToken, $hasZero, &$output)
    {
        $firstElement = reset($arrayToken);
        list($tokenType, $token) = each($firstElement);

        $context = $this->context;
        $result = false;

        switch ($tokenType) {
            case Translator::TYPE_JOIN:
                $this->setRollbackPoint();
                $this->handleJoin($token);
                array_shift($arrayToken);
                $result = $this->conditionallyRollback(
                    $this->getBooleanExpression($arrayToken, $hasZero, $output)
                );
                break;

            case Translator::TYPE_PARAMETER:
                $result = $this->getBooleanParameter($token, $hasZero, $output);
                break;

            case Translator::TYPE_VALUE:
                $result = $this->getBooleanProperty($token, $output);
                break;

            case Translator::TYPE_FUNCTION:
                $name = $token['function'];
                $arguments = $token['arguments'];
                $result = $this->getBooleanFunction($name, $arguments, $hasZero, $output);
                break;
        }

        $this->context = $context;
        return $result;
    }

    protected function getIntegerExpression($arrayToken, $hasZero, &$output)
    {
        $firstElement = reset($arrayToken);
        list($tokenType, $token) = each($firstElement);

        $context = $this->context;
        $result = false;

        switch ($tokenType) {
            case Translator::TYPE_JOIN:
                $this->setRollbackPoint();
                $this->handleJoin($token);
                array_shift($arrayToken);
                $result = $this->conditionallyRollback(
                    $this->getIntegerExpression($arrayToken, $hasZero, $output)
                );
                break;

            case Translator::TYPE_PARAMETER:
                $result = $this->getIntegerParameter($token, $hasZero, $output);
                break;

            case Translator::TYPE_VALUE:
                $result = $this->getIntegerProperty($token, $output);
                break;

            case Translator::TYPE_FUNCTION:
                $name = $token['function'];
                $arguments = $token['arguments'];

                if (count($arguments) < 2) {
                    return false;
                }

                $result = $this->getIntegerBinaryFunction($name, $arguments[0], $arguments[1], $hasZero, $output);
                break;
        }

        $this->context = $context;
        return $result;
    }

    protected function getFloatExpression($arrayToken, $hasZero, &$output)
    {
        $firstElement = reset($arrayToken);
        list($tokenType, $token) = each($firstElement);

        $context = $this->context;
        $result = false;

        switch ($tokenType) {
            case Translator::TYPE_JOIN:
                $this->setRollbackPoint();
                $this->handleJoin($token);
                array_shift($arrayToken);
                $result = $this->conditionallyRollback(
                    $this->getFloatExpression($arrayToken, $hasZero, $output)
                );
                break;

            case Translator::TYPE_PARAMETER:
                $result = $this->getFloatParameter($token, $hasZero, $output);
                break;

            case Translator::TYPE_VALUE:
                $result = $this->getFloatProperty($token, $output);
                break;

            case Translator::TYPE_FUNCTION:
                $name = $token['function'];
                $arguments = $token['arguments'];

                if (count($arguments) < 2) {
                    return false;
                }

                $result = $this->getFloatBinaryFunction($name, $arguments[0], $arguments[1], $hasZero, $output);
                break;
        }

        $this->context = $context;
        return $result;
    }

    protected function getStringExpression($arrayToken, $hasZero, &$output)
    {
        $firstElement = reset($arrayToken);
        list($tokenType, $token) = each($firstElement);

        $context = $this->context;
        $result = false;

        switch ($tokenType) {
            case Translator::TYPE_JOIN:
                $this->setRollbackPoint();
                $this->handleJoin($token);
                array_shift($arrayToken);
                $result = $this->conditionallyRollback(
                    $this->getStringExpression($arrayToken, $hasZero, $output)
                );
                break;

            case Translator::TYPE_PARAMETER:
                $result = $this->getStringParameter($token, $hasZero, $output);
                break;

            case Translator::TYPE_VALUE:
                $result = $this->getStringProperty($token, $output);
                break;
        }

        $this->context = $context;
        return $result;
    }

    protected function getBooleanFunction($name, $arguments, $hasZero, &$output)
    {
        $countArguments = count($arguments);

        if ($countArguments === 1) {
            $argument = current($arguments);
            return $this->getBooleanUnaryFunction($name, $argument, $hasZero, $output);
        }

        if ($countArguments === 2) {
            list($argumentA, $argumentB) = $arguments;
            return $this->getBooleanBinaryFunction($name, $argumentA, $argumentB, $hasZero, $output);
        }

        return false;
    }

    protected function getBooleanUnaryFunction($name, $argument, $hasZero, &$expression)
    {
        if ($name !== 'not') {
            return false;
        }

        if (!$this->getBooleanExpression($argument, $hasZero, $childExpression)) {
            return false;
        }

        $expression = new OperatorNot($childExpression);
        return true;
    }

    protected function getBooleanBinaryFunction($name, $argumentA, $argumentB, $hasZero, &$expression)
    {
        switch ($name) {
            case 'equal':
                return $this->getEqualFunction($argumentA, $argumentB, $hasZero, $expression);

            case 'and':
                return $this->getAndFunction($argumentA, $argumentB, $hasZero, $expression);

            case 'or':
                return $this->getOrFunction($argumentA, $argumentB, $hasZero, $expression);

            case 'notEqual':
                return $this->getNotEqualFunction($argumentA, $argumentB, $hasZero, $expression);

            case 'less':
                return $this->getLessFunction($argumentA, $argumentB, $hasZero, $expression);

            case 'lessEqual':
                return $this->getLessEqualFunction($argumentA, $argumentB, $hasZero, $expression);

            case 'greater':
                return $this->getGreaterFunction($argumentA, $argumentB, $hasZero, $expression);

            case 'greaterEqual':
                return $this->getGreaterEqualFunction($argumentA, $argumentB, $hasZero, $expression);

            case 'match':
                return $this->getMatchFunction($argumentA, $argumentB, $expression);

            default:
                return false;
        }
    }

    protected function getEqualFunction($argumentA, $argumentB, $hasZero, &$expression)
    {
        if (
            (
                $this->getBooleanExpression($argumentA, $hasZero, $expressionA) &&
                $this->getBooleanExpression($argumentB, $hasZero, $expressionB)
            ) || (
                $this->getIntegerExpression($argumentA, $hasZero, $expressionA) &&
                $this->getIntegerExpression($argumentB, $hasZero, $expressionB)
            ) || (
                $this->getFloatExpression($argumentA, $hasZero, $expressionA) &&
                $this->getFloatExpression($argumentB, $hasZero, $expressionB)
            ) || (
                $this->getStringExpression($argumentA, $hasZero, $expressionA) &&
                $this->getStringExpression($argumentB, $hasZero, $expressionB)
            )
        ) {
            $expression = new OperatorEqual($expressionA, $expressionB);
            return true;
        }

        return false;
    }

    protected function getAndFunction($argumentA, $argumentB, $hasZero, &$expression)
    {
        if (
            !$this->getBooleanExpression($argumentA, $hasZero, $outputA) ||
            !$this->getBooleanExpression($argumentB, $hasZero, $outputB)
        ) {
            return false;
        }

        $expression = new OperatorAnd($outputA, $outputB);
        return true;
    }

    protected function getOrFunction($argumentA, $argumentB, $hasZero, &$expression)
    {
        if (
            !$this->getBooleanExpression($argumentA, $hasZero, $outputA) ||
            !$this->getBooleanExpression($argumentB, $hasZero, $outputB)
        ) {
            return false;
        }

        $expression = new OperatorOr($outputA, $outputB);
        return true;
    }

    protected function getNotEqualFunction($argumentA, $argumentB, $hasZero, &$expression)
    {
        if (!$this->getEqualFunction($argumentA, $argumentB, $hasZero, $equalExpression)) {
            return false;
        }

        $expression = new OperatorNot($equalExpression);
        return true;
    }

    protected function getLessFunction($argumentA, $argumentB, $hasZero, &$expression)
    {
        if (
            (
                $this->getIntegerExpression($argumentA, $hasZero, $expressionA) &&
                $this->getIntegerExpression($argumentB, $hasZero, $expressionB)
            ) || (
                $this->getFloatExpression($argumentA, $hasZero, $expressionA) &&
                $this->getFloatExpression($argumentB, $hasZero, $expressionB)
            ) || (
                $this->getStringExpression($argumentA, $hasZero, $expressionA) &&
                $this->getStringExpression($argumentB, $hasZero, $expressionB)
            )
        ) {
            $expression = new OperatorLess($expressionA, $expressionB);
            return true;
        }

        return false;
    }

    protected function getLessEqualFunction($argumentA, $argumentB, $hasZero, &$expression)
    {
        if (
            (
                $this->getIntegerExpression($argumentA, $hasZero, $expressionA) &&
                $this->getIntegerExpression($argumentB, $hasZero, $expressionB)
            ) || (
                $this->getFloatExpression($argumentA, $hasZero, $expressionA) &&
                $this->getFloatExpression($argumentB, $hasZero, $expressionB)
            ) || (
                $this->getStringExpression($argumentA, $hasZero, $expressionA) &&
                $this->getStringExpression($argumentB, $hasZero, $expressionB)
            )
        ) {
            $expression = new OperatorLessEqual($expressionA, $expressionB);
            return true;
        }

        return false;
    }

    protected function getGreaterFunction($argumentA, $argumentB, $hasZero, &$expression)
    {
        if (
            (
                $this->getIntegerExpression($argumentA, $hasZero, $expressionA) &&
                $this->getIntegerExpression($argumentB, $hasZero, $expressionB)
            ) || (
                $this->getFloatExpression($argumentA, $hasZero, $expressionA) &&
                $this->getFloatExpression($argumentB, $hasZero, $expressionB)
            ) || (
                $this->getStringExpression($argumentA, $hasZero, $expressionA) &&
                $this->getStringExpression($argumentB, $hasZero, $expressionB)
            )
        ) {
            $expression = new OperatorGreater($expressionA, $expressionB);
            return true;
        }

        return false;
    }

    protected function getGreaterEqualFunction($argumentA, $argumentB, $hasZero, &$expression)
    {
        if (
            (
                $this->getIntegerExpression($argumentA, $hasZero, $expressionA) &&
                $this->getIntegerExpression($argumentB, $hasZero, $expressionB)
            ) || (
                $this->getFloatExpression($argumentA, $hasZero, $expressionA) &&
                $this->getFloatExpression($argumentB, $hasZero, $expressionB)
            ) || (
                $this->getStringExpression($argumentA, $hasZero, $expressionA) &&
                $this->getStringExpression($argumentB, $hasZero, $expressionB)
            )
        ) {
            $expression = new OperatorGreaterEqual($expressionA, $expressionB);
            return true;
        }

        return false;
    }

    protected function getIntegerBinaryFunction($name, $argumentA, $argumentB, $hasZero, &$expression)
    {
        if (
            !$this->getIntegerExpression($argumentA, $hasZero, $expressionA) ||
            !$this->getIntegerExpression($argumentB, $hasZero, $expressionB)
        ) {
            return false;
        }

        switch ($name) {
            case 'plus':
                $expression = new OperatorPlus($expressionA, $expressionB);
                return true;

            case 'minus':
                $expression = new OperatorMinus($expressionA, $expressionB);
                return true;

            case 'times':
                $expression = new OperatorTimes($expressionA, $expressionB);
                return true;

            case 'divides':
                $expression = new OperatorDivides($expressionA, $expressionB);
                return true;

            default:
                return false;
        }
    }

    protected function getFloatBinaryFunction($name, $argumentA, $argumentB, $hasZero, &$expression)
    {
        if (
            !$this->getFloatExpression($argumentA, $hasZero, $expressionA) ||
            !$this->getFloatExpression($argumentB, $hasZero, $expressionB)
        ) {
            return false;
        }

        switch ($name) {
            case 'plus':
                $expression = new OperatorPlus($expressionA, $expressionB);
                return true;

            case 'minus':
                $expression = new OperatorMinus($expressionA, $expressionB);
                return true;

            case 'times':
                $expression = new OperatorTimes($expressionA, $expressionB);
                return true;

            case 'divides':
                $expression = new OperatorDivides($expressionA, $expressionB);
                return true;

            default:
                return false;
        }
    }

    protected function getMatchFunction($property, $pattern, &$expression)
    {
        if (!isset($property) || count($property) < 2) {
            return false;
        }

        $state = $this->context;

        $property = $this->followJoins($property);
        $firstElementProperty = reset($property);
        list($tokenTypeA, $propertyToken) = each($firstElementProperty);
        if ($tokenTypeA !== Translator::TYPE_VALUE) {
            $this->context = $state;
            return false;
        }

        $firstElementPattern = reset($pattern);
        list($tokenTypeB, $name) = each($firstElementPattern);
        if ($tokenTypeB !== Translator::TYPE_PARAMETER) {
            $this->context = $state;
            return false;
        }

        if (
            !$this->getStringProperty($propertyToken, $argumentExpression) ||
            !$this->getStringParameter($name, self::$REQUIRED, $patternExpression)
        ) {
            $this->context = $state;
            return false;
        }

        $expression = new OperatorRegexpBinary($argumentExpression, $patternExpression);
        $this->context = $state;
        return true;
    }

    protected function getBooleanProperty($propertyToken, &$output)
    {
        return $this->getProperty($propertyToken, Output::TYPE_BOOLEAN, $output);
    }

    protected function getBooleanParameter($name, $hasZero, &$output)
    {
        return $this->getParameter($name, 'boolean', $hasZero, $output);
    }

    protected function getIntegerProperty($propertyToken, &$output)
    {
        return $this->getProperty($propertyToken, Output::TYPE_INTEGER, $output);
    }

    protected function getIntegerParameter($name, $hasZero, &$output)
    {
        return $this->getParameter($name, 'integer', $hasZero, $output);
    }

    protected function getFloatProperty($propertyToken, &$output)
    {
        return $this->getProperty($propertyToken, Output::TYPE_FLOAT, $output);
    }

    protected function getFloatParameter($name, $hasZero, &$output)
    {
        return $this->getParameter($name, 'float', $hasZero, $output);
    }

    protected function getStringProperty($propertyToken, &$output)
    {
        return $this->getProperty($propertyToken, Output::TYPE_STRING, $output);
    }

    protected function getStringParameter($name, $hasZero, &$output)
    {
        return $this->getParameter($name, 'string', $hasZero, $output);
    }

    protected function getParameter($name, $type, $hasZero, &$output)
    {
        $id = $this->input->useArgument($name, $type, $hasZero);

        if ($id === null) {
            return false;
        }

        $output = new Parameter($id);
        return true;
    }

    protected static function scanParameter($input, &$name)
    {
        // scan the next token of the supplied arrayToken
        $input = reset($input);

        list($tokenType, $token) = each($input);

        if ($tokenType !== Translator::TYPE_PARAMETER) {
            return false;
        }

        $name = $token;
        return true;
    }

    protected static function scanProperty($input, &$table, &$name, &$type, &$hasZero)
    {
        list($tokenType, $token) = each($input);

        if ($tokenType !== Translator::TYPE_VALUE) {
            return false;
        }

        $table = $token['table'];
        $name = $token['expression'];
        $type = $token['type'];
        $hasZero = $token['hasZero'];
        return true;
    }

    protected static function scanFunction($input, &$name, &$arguments)
    {
        list($tokenType, $token) = each($input);

        if ($tokenType !== Translator::TYPE_FUNCTION) {
            return false;
        }

        $name = $token['function'];
        $arguments = $token['arguments'];
        return true;
    }

    protected static function scanJoin($input, &$object)
    {
        list($tokenType, $token) = each($input);

        if ($tokenType !== Translator::TYPE_JOIN) {
            return false;
        }

        $object = $token;
        return true;
    }

    protected function conditionallyRollback($success)
    {
        if ($success) {
            $this->clearRollbackPoint();
            return true;
        } else {
            $this->rollback();
            return false;
        }
    }

    protected function setRollbackPoint()
    {
        $this->rollbackPoint[] = array($this->context, $this->input);
        $this->mysql->setRollbackPoint();
    }

    protected function clearRollbackPoint()
    {
        array_pop($this->rollbackPoint);
        $this->mysql->clearRollbackPoint();
    }

    protected function rollback()
    {
        $rollbackState = array_pop($this->rollbackPoint);
        $this->context = $rollbackState[0];
        $this->input = $rollbackState[1];
        $this->mysql->rollback();
    }
}
