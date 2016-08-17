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

use Datto\Cinnabari\Compiler;
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
    protected $contextJoin;

    /** @var array */
    protected $rollbackPoint;

    protected static $REQUIRED = false;
    protected static $OPTIONAL = true;

    public function __construct()
    {
        $this->contextJoin = null;
        $this->rollbackPoint = array();
    }

    /**
     * @param array $token
     * @param AbstractExpression|null $output
     * @param int $type
     */
    abstract protected function getProperty($token, &$output, &$type);

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

        if (!$this->getExpression($arguments[0], self::$REQUIRED, $where, $type)) {
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

    protected function getExpression($arrayToken, $hasZero, &$expression, &$type)
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
                    $this->getExpression($arrayToken, $hasZero, $expression, $type)
                );
                break;

            case Translator::TYPE_PARAMETER:
                $result = $this->getParameter($token, $hasZero, $expression);
                break;

            case Translator::TYPE_VALUE:
                $result = $this->getProperty($token, $expression, $type);
                break;

            case Translator::TYPE_FUNCTION:
                $name = $token['function'];
                $arguments = $token['arguments'];
                $result = $this->getFunction($name, $arguments, $hasZero, $expression, $type);
                break;
        }

        $this->context = $context;
        return $result;
    }

    protected function getFunction($name, $arguments, $hasZero, &$output, &$type)
    {
        $countArguments = count($arguments);

        if ($countArguments === 1) {
            $argument = current($arguments);
            return $this->getUnaryFunction($name, $argument, $hasZero, $output, $type);
        }

        if ($countArguments === 2) {
            list($argumentA, $argumentB) = $arguments;
            return $this->getBinaryFunction($name, $argumentA, $argumentB, $hasZero, $output, $type);
        }

        return false;
    }

    protected function getUnaryFunction($name, $argument, $hasZero, &$expression, &$type)
    {
        if ($name !== 'not') {
            return false;
        }

        if (!$this->getExpression($argument, $hasZero, $childExpression, $argumentType)) {
            return false;
        }

        $expression = new OperatorNot($childExpression);
        $type = self::getReturnTypeFromFunctionName($name, $argumentType, false);
        return true;
    }

    protected function getBinaryFunction($name, $argumentA, $argumentB, $hasZero, &$expression, &$type)
    {
        if (
            !$this->getExpression($argumentA, $hasZero, $expressionA, $argumentTypeOne) ||
            !$this->getExpression($argumentB, $hasZero, $expressionB, $argumentTypeTwo)
        ) {
            return false;
        }
        
        $type = self::getReturnTypeFromFunctionName($name, $argumentTypeOne, $argumentTypeTwo);

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

            case 'equal':
                $expression = new OperatorEqual($expressionA, $expressionB);
                return true;

            case 'and':
                $expression = new OperatorAnd($expressionA, $expressionB);
                return true;

            case 'or':
                $expression = new OperatorOr($expressionA, $expressionB);
                return true;

            case 'notEqual':
                $equalExpression = new OperatorEqual($expressionA, $expressionB);
                $expression = new OperatorNot($equalExpression);
                return true;

            case 'less':
                $expression = new OperatorLess($expressionA, $expressionB);
                return true;

            case 'lessEqual':
                $expression = new OperatorLessEqual($expressionA, $expressionB);
                return true;

            case 'greater':
                $expression = new OperatorGreater($expressionA, $expressionB);
                return true;

            case 'greaterEqual':
                $expression = new OperatorGreaterEqual($expressionA, $expressionB);
                return true;

            case 'match':
                return $this->getMatchFunction($argumentA, $argumentB, $expression);

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
            !$this->getProperty($propertyToken, $argumentExpression, $type) ||
            !$this->getParameter($name, self::$REQUIRED, $patternExpression)
        ) {
            $this->context = $state;
            return false;
        }

        $expression = new OperatorRegexpBinary($argumentExpression, $patternExpression);
        $this->context = $state;
        return true;
    }
    
    protected static function getReturnTypeFromFunctionName($name, $typeOne, $typeTwo)
    {
        $allSignatures = Compiler::getSignatures();
        if (array_key_exists($name, $allSignatures)) {
            $signatures = $allSignatures[$name];
            
            foreach ($signatures as $signature) {
                if (self::signatureMatchesArguments($signature, $typeOne, $typeTwo)) {
                    return $signature['return'];
                }
            }
            
            // just return the return type of the first signature
            return $signatures[0]['return'];
        } else {
            return false;
        }
    }
    
    protected static function signatureMatchesArguments($signature, $typeOne, $typeTwo)
    {
        if ($signature['arguments'][0] !== $typeOne) {
            return false;
        }

        // TODO: assumes functions take at most 2 arguments for simplicity
        if (count($signature['arguments']) >= 2) {
            return $signature['arguments'][1] === $typeTwo;
        } else {
            return true;
        }
    }

    protected function getParameter($name, $hasZero, &$output)
    {
        $id = $this->input->useArgument($name, $hasZero);

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
        $this->rollbackPoint[] = array($this->context, $this->contextJoin, $this->input, $this->mysql);
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
        $this->input = $rollbackState[2];
        $this->mysql = $rollbackState[3];
    }
}
