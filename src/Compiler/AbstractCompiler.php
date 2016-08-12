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
use Datto\Cinnabari\Format\Arguments;
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

    /** @var Arguments */
    protected $arguments;

    /** @var int */
    protected $context;
    
    /** @var AbstractMysql */
    protected $mysql;

    /** @var array */
    protected $contextJoin;

    /** @var array */
    protected $rollbackPoint;

    public function __construct()
    {
        $this->contextJoin = null;
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
        if (!$this->getBooleanExpression($arguments[0], $where)) {
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
        if ($token['isContextual']) {
            $this->contextJoin = $token;
        }

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

    protected function getExpression($arrayToken, &$expression, &$type)
    {
        return $this->getBooleanExpression($arrayToken, $expression)
            || $this->getNumericExpression($arrayToken, $expression, $type)
            || $this->getStringExpression($arrayToken, $expression);
    }

    private function getBooleanExpression($arrayToken, &$output)
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
                    $this->getBooleanExpression($arrayToken, $output)
                );
                break;

            case Translator::TYPE_PARAMETER:
                $result = $this->getBooleanParameter($token, $output);
                break;

            case Translator::TYPE_VALUE:
                $result = $this->getBooleanProperty($token, $output);
                break;

            case Translator::TYPE_FUNCTION:
                $name = $token['function'];
                $arguments = $token['arguments'];
                $result = $this->getBooleanFunction($name, $arguments, $output);
                break;
        }

        $this->context = $context;
        return $result;
    }

    protected function getNumericExpression($arrayToken, &$output, &$type)
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
                    $this->getNumericExpression($arrayToken, $output, $type)
                );
                break;

            case Translator::TYPE_PARAMETER:
                $result = $this->getNumericParameter($token, $output, $type);
                break;

            case Translator::TYPE_VALUE:
                $result = $this->getNumericProperty($token, $output, $type);
                break;

            case Translator::TYPE_FUNCTION:
                $name = $token['function'];
                $arguments = $token['arguments'];

                if (count($arguments) < 2) {
                    return false;
                }

                $result = $this->getNumericBinaryFunction($name, $arguments[0], $arguments[1], $output, $type);
                break;
        }

        $this->context = $context;
        return $result;
    }

    protected function getStringExpression($arrayToken, &$output)
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
                    $this->getStringExpression($arrayToken, $output)
                );
                break;

            case Translator::TYPE_PARAMETER:
                $result = $this->getStringParameter($token, $output);
                break;

            case Translator::TYPE_VALUE:
                $result = $this->getStringProperty($token, $output);
                break;
        }

        $this->context = $context;
        return $result;
    }

    protected function getBooleanFunction($name, $arguments, &$output)
    {
        $countArguments = count($arguments);

        if ($countArguments === 1) {
            $argument = current($arguments);
            return $this->getBooleanUnaryFunction($name, $argument, $output);
        }

        if ($countArguments === 2) {
            list($argumentA, $argumentB) = $arguments;
            return $this->getBooleanBinaryFunction($name, $argumentA, $argumentB, $output);
        }

        return false;
    }

    protected function getBooleanUnaryFunction($name, $argument, &$expression)
    {
        if ($name !== 'not') {
            return false;
        }

        if (!$this->getBooleanExpression($argument, $childExpression)) {
            return false;
        }

        $expression = new OperatorNot($childExpression);
        return true;
    }

    protected function getBooleanBinaryFunction($name, $argumentA, $argumentB, &$expression)
    {
        switch ($name) {
            case 'equal':
                return $this->getEqualFunction($argumentA, $argumentB, $expression);

            case 'and':
                return $this->getAndFunction($argumentA, $argumentB, $expression);

            case 'or':
                return $this->getOrFunction($argumentA, $argumentB, $expression);

            case 'notEqual':
                return $this->getNotEqualFunction($argumentA, $argumentB, $expression);

            case 'less':
                return $this->getLessFunction($argumentA, $argumentB, $expression);

            case 'lessEqual':
                return $this->getLessEqualFunction($argumentA, $argumentB, $expression);

            case 'greater':
                return $this->getGreaterFunction($argumentA, $argumentB, $expression);

            case 'greaterEqual':
                return $this->getGreaterEqualFunction($argumentA, $argumentB, $expression);

            case 'match':
                return $this->getMatchFunction($argumentA, $argumentB, $expression);

            default:
                return false;
        }
    }

    protected function getEqualFunction($argumentA, $argumentB, &$expression)
    {
        if (
            (
                $this->getBooleanExpression($argumentA, $expressionA) &&
                $this->getBooleanExpression($argumentB, $expressionB)
            ) || (
                $this->getNumericExpression($argumentA, $expressionA, $typeA) &&
                $this->getNumericExpression($argumentB, $expressionB, $typeB)
            ) || (
                $this->getStringExpression($argumentA, $expressionA) &&
                $this->getStringExpression($argumentB, $expressionB)
            )
        ) {
            $expression = new OperatorEqual($expressionA, $expressionB);
            return true;
        }

        return false;
    }

    protected function getAndFunction($argumentA, $argumentB, &$expression)
    {
        if (
            !$this->getBooleanExpression($argumentA, $outputA) ||
            !$this->getBooleanExpression($argumentB, $outputB)
        ) {
            return false;
        }

        $expression = new OperatorAnd($outputA, $outputB);
        return true;
    }

    protected function getOrFunction($argumentA, $argumentB, &$expression)
    {
        if (
            !$this->getBooleanExpression($argumentA, $outputA) ||
            !$this->getBooleanExpression($argumentB, $outputB)
        ) {
            return false;
        }

        $expression = new OperatorOr($outputA, $outputB);
        return true;
    }

    protected function getNotEqualFunction($argumentA, $argumentB, &$expression)
    {
        if (!$this->getEqualFunction($argumentA, $argumentB, $equalExpression)) {
            return false;
        }

        $expression = new OperatorNot($equalExpression);
        return true;
    }

    protected function getLessFunction($argumentA, $argumentB, &$expression)
    {
        if (
            (
                $this->getNumericExpression($argumentA, $expressionA, $typeA) &&
                $this->getNumericExpression($argumentB, $expressionB, $typeB)
            ) || (
                $this->getStringExpression($argumentA, $expressionA) &&
                $this->getStringExpression($argumentB, $expressionB)
            )
        ) {
            $expression = new OperatorLess($expressionA, $expressionB);
            return true;
        }

        return false;
    }

    protected function getLessEqualFunction($argumentA, $argumentB, &$expression)
    {
        if (
            (
                $this->getNumericExpression($argumentA, $expressionA, $typeA) &&
                $this->getNumericExpression($argumentB, $expressionB, $typeB)
            ) || (
                $this->getStringExpression($argumentA, $expressionA) &&
                $this->getStringExpression($argumentB, $expressionB)
            )
        ) {
            $expression = new OperatorLessEqual($expressionA, $expressionB);
            return true;
        }

        return false;
    }

    protected function getGreaterFunction($argumentA, $argumentB, &$expression)
    {
        if (
            (
                $this->getNumericExpression($argumentA, $expressionA, $typeA) &&
                $this->getNumericExpression($argumentB, $expressionB, $typeB)
            ) || (
                $this->getStringExpression($argumentA, $expressionA) &&
                $this->getStringExpression($argumentB, $expressionB)
            )
        ) {
            $expression = new OperatorGreater($expressionA, $expressionB);
            return true;
        }

        return false;
    }

    protected function getGreaterEqualFunction($argumentA, $argumentB, &$expression)
    {
        if (
            (
                $this->getNumericExpression($argumentA, $expressionA, $typeA) &&
                $this->getNumericExpression($argumentB, $expressionB, $typeB)
            ) || (
                $this->getStringExpression($argumentA, $expressionA) &&
                $this->getStringExpression($argumentB, $expressionB)
            )
        ) {
            $expression = new OperatorGreaterEqual($expressionA, $expressionB);
            return true;
        }

        return false;
    }

    protected function getNumericBinaryFunction($name, $argumentA, $argumentB, &$expression, &$type)
    {
        if (
            !$this->getNumericExpression($argumentA, $expressionA, $typeA) ||
            !$this->getNumericExpression($argumentB, $expressionB, $typeB)
        ) {
            return false;
        }

        $aIsAnInteger = ($typeA === Output::TYPE_INTEGER);
        $bIsAnInteger = ($typeB === Output::TYPE_INTEGER);
        $aIsAFloat = ($typeA === Output::TYPE_FLOAT);
        $bIsAFloat = ($typeB === Output::TYPE_FLOAT);

        if (($name === 'plus') || ($name === 'minus') || ($name === 'times') || ($name === 'divides')) {
            if ($aIsAnInteger && $bIsAnInteger) {
                $type = Output::TYPE_INTEGER;
            } elseif (($aIsAnInteger && $bIsAFloat) || ($aIsAFloat && $bIsAnInteger) || ($aIsAFloat && $bIsAFloat)) {
                $type = Output::TYPE_FLOAT;
            } else {
                return false;
            }
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
            !$this->getStringParameter($name, $patternExpression)
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

    protected function getBooleanParameter($name, &$output)
    {
        return $this->getParameter($name, 'boolean', $output);
    }

    protected function getNumericProperty($propertyToken, &$output, &$type)
    {
        if ($this->getProperty($propertyToken, Output::TYPE_INTEGER, $output)) {
            $type = Output::TYPE_INTEGER;
            return true;
        } elseif ($this->getProperty($propertyToken, Output::TYPE_FLOAT, $output)) {
            $type = Output::TYPE_FLOAT;
            return true;
        } else {
            return false;
        }
    }

    protected function getNumericParameter($name, &$output, &$type)
    {
        if ($this->getParameter($name, 'integer', $output)) {
            $type = Output::TYPE_INTEGER;
            return true;
        } elseif ($this->getParameter($name, 'double', $output)) {
            $type = Output::TYPE_FLOAT;
            return true;
        } else {
            return false;
        }
    }

    protected function getStringProperty($propertyToken, &$output)
    {
        return $this->getProperty($propertyToken, Output::TYPE_STRING, $output);
    }

    protected function getStringParameter($name, &$output)
    {
        return $this->getParameter($name, 'string', $output);
    }

    protected function getParameter($name, $type, &$output)
    {
        $id = $this->arguments->useArgument($name, $type);

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
        $this->rollbackPoint[] = array($this->context, $this->contextJoin, $this->mysql);
    }

    protected function clearRollbackPoint()
    {
        array_pop($this->rollbackPoint);
    }

    protected function rollback()
    {
        $rollbackState = array_pop($this->rollbackPoint);
        $this->context = $rollbackState[0];
        $this->contextJoin = $rollbackState[1];
        $this->mysql = $rollbackState[2];
    }
}
