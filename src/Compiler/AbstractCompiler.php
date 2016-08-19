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
use Datto\Cinnabari\Mysql\Expression\FunctionConcatenate;
use Datto\Cinnabari\Mysql\Expression\FunctionLength;
use Datto\Cinnabari\Mysql\Expression\FunctionLower;
use Datto\Cinnabari\Mysql\Expression\FunctionSubstring;
use Datto\Cinnabari\Mysql\Expression\FunctionUpper;
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

    /** @var AbstractMysql */
    protected $subquery;

    /** @var int */
    protected $subqueryContext;

    /** @var array */
    protected $contextJoin;

    /** @var array */
    protected $rollbackPoint;

    protected static $REQUIRED = false;
    protected static $OPTIONAL = true;

    public function __construct()
    {
        $this->subquery = null;
        $this->subqueryContext = null;
        $this->contextJoin = null;
        $this->rollbackPoint = array();
    }

    /**
     * @param array $token
     * @param AbstractExpression|null $output
     * @param int $type
     */
    abstract protected function getProperty($token, &$output, &$type);

    protected function optimize($topLevelFunction, $request)
    {
        $method = self::analyze($topLevelFunction, $request);

        // Rule: remove unnecessary sort functions
        if (
            $method['is']['count'] ||
            $method['is']['aggregator'] ||
            $method['is']['set'] ||
            $method['is']['delete']
        ) {
            if (
                $method['before']['sorts']['slices'] || (
                    $method['sorts'] && !$method['slices']
                )
            ) {
                $request = self::removeFunction('sort', $request, $sort);
                $method['sorts'] = false;
                $method['before']['sorts']['filters'] = false;
                $method['before']['sorts']['slices'] = false;
                $method['before']['filters']['sorts'] = false;
                $method['before']['slices']['sorts'] = false;
            }
        }

        // Rule: slices imply a sort
        if (
            self::scanTable($request, $table, $id, $hasZero) && (
                !$method['before']['slices']['sorts'] || (
                    $method['slices'] && !$method['sorts']
                )
            )
        ) {
            // TODO: get the type of the table's id; don't assume int
            $type = Output::TYPE_INTEGER;
            $valueToken = array(
                Translator::TYPE_VALUE => array(
                    'table' => $table,
                    'expression' => $id,
                    'type' => $type,
                    'hasZero' => $hasZero
                )
            );
            $sortFunction = array(
                Translator::TYPE_FUNCTION => array(
                    'function' => 'sort',
                    'arguments' => array(array($valueToken))
                )
            );
            $request = self::insertFunctionBefore($sortFunction, 'slice', $request);
        }

        // Rule: slices in countsaggregators require subqueries
        if ($method['is']['count'] || $method['is']['aggregator']) {
            if ($method['slices']) {
                $forkFunction = array(
                    Translator::TYPE_FUNCTION => array(
                        'function' => 'fork',
                        'arguments' => array()
                    )
                );

                $request = self::insertFunctionAfter($forkFunction, 'slice', $request);
            }
        }

        // Rule: when filters and sorts are adjacent, force the filter to appear before the sort
        if (
            $method['before']['filters']['sorts'] && (
                !$method['slices'] || (
                    // the slice cannot be between the filter and the sort
                    $method['before']['filters']['slices'] === $method['before']['sorts']['slices']
                )
            )
        ) {
            $request = self::removeFunction('sort', $request, $removedFunction);
            $request = self::insertFunctionAfter($removedFunction, 'filter', $request);
            $method['before']['filters']['sorts'] = false;
            $method['before']['sorts']['filters'] = true;
        }

        return $request;
    }

    private static function removeFunction($functionName, $request, &$removedFunction)
    {
        return array_filter(
            $request,
            function ($wrappedToken) use ($functionName, &$removedFunction) {
                list($tokenType, $token) = each($wrappedToken);

                $include = ($tokenType !== Translator::TYPE_FUNCTION) ||
                    $token['function'] !== $functionName;

                if (!$include) {
                    $removedFunction = $wrappedToken;
                }

                return $include;
            }
        );
    }

    private static function insertFunctionBefore($function, $target, $request)
    {
        return self::insertFunctionRelativeTo(true, $function, $target, $request);
    }

    private static function insertFunctionAfter($function, $target, $request)
    {
        return self::insertFunctionRelativeTo(false, $function, $target, $request);
    }

    private static function insertFunctionRelativeTo($insertBefore, $function, $target, $request)
    {
        return array_reduce(
            $request,
            function ($carry, $wrappedToken) use ($insertBefore, $function, $target) {
                list($type, $token) = each($wrappedToken);
                $tokensToAdd = array($wrappedToken);
                if ($type === Translator::TYPE_FUNCTION && $token['function'] === $target) {
                    if ($insertBefore) {
                        array_unshift($tokensToAdd, $function);
                    } else {
                        $tokensToAdd[] =  $function;
                    }
                }
                return array_merge($carry, $tokensToAdd);
            },
            array()
        );
    }

    protected function analyze($topLevelFunction, $translatedRequest)
    {
        // is a get, delete, set, insert, count, aggregator
        $method = array();
        $method['is'] = array();
        $method['is']['get'] = false;
        $method['is']['delete'] = false;
        $method['is']['set'] = false;
        $method['is']['insert'] = false;
        $method['is']['count'] = false;
        $method['is']['aggregator'] = false;

        if (array_key_exists($topLevelFunction, $method['is'])) {
            $method['is'][$topLevelFunction] = true;
        } else {
            $method['is']['aggregator'] = true;
        }

        // order of the list functions
        $functions = array();
        foreach ($translatedRequest as $wrappedToken) {
            list($tokenType, $token) = each($wrappedToken);
            if ($tokenType === Translator::TYPE_FUNCTION) {
                $functions[] = $token['function'];
            }
        }

        $method['before'] = array(
            'filters' => array('sorts' => false, 'slices' => false),
            'sorts' => array('filters' => false, 'slices' => false),
            'slices' => array('filters' => false, 'sorts' => false)
        );
        $filterIndex = array_search('filter', $functions, true);
        $sortIndex = array_search('sort', $functions, true);
        $sliceIndex = array_search('slice', $functions, true);
        $method['filters'] = $filterIndex !== false;
        $method['sorts'] = $sortIndex !== false;
        $method['slices'] = $sliceIndex !== false;
        if ($method['filters'] && $method['sorts']) {
            $method['before']['filters']['sorts'] = $filterIndex > $sortIndex;
            $method['before']['sorts']['filters'] = $sortIndex > $filterIndex;
        }
        if ($method['filters'] && $method['slices']) {
            $method['before']['filters']['slices'] = $filterIndex > $sliceIndex;
            $method['before']['slices']['filters'] = $sliceIndex > $filterIndex;
        }
        if ($method['sorts'] && $method['slices']) {
            $method['before']['sorts']['slices'] = $sortIndex > $sliceIndex;
            $method['before']['slices']['sorts'] = $sliceIndex > $sortIndex;
        }

        return $method;
    }

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

        if (isset($this->subquery)) {
            $this->subqueryContext = $this->subquery->addJoin(
                $this->subqueryContext,
                $token['tableB'],
                $token['expression'],
                $token['hasZero'],
                $token['hasMany']
            );
        } else {
            $this->context = $this->mysql->addJoin(
                $this->context,
                $token['tableB'],
                $token['expression'],
                $token['hasZero'],
                $token['hasMany']
            );
        }
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

        if ($countArguments === 3) {
            list($argumentA, $argumentB, $argumentC) = $arguments;
            return $this->getTernaryFunction($name, $argumentA, $argumentB, $argumentC, $hasZero, $output, $type);
        }

        return false;
    }

    protected function getUnaryFunction($name, $argument, $hasZero, &$expression, &$type)
    {
        if ($name === 'length') {
            return $this->getLengthFunction($argument, $hasZero, $expression, $type);
        }

        if (!$this->getExpression($argument, $hasZero, $childExpression, $argumentType)) {
            return false;
        }

        $type = self::getReturnTypeFromFunctionName($name, $argumentType, false, false);

        switch ($name) {
            case 'uppercase':
                $expression = new FunctionUpper($childExpression);
                return true;

            case 'lowercase':
                $expression = new FunctionLower($childExpression);
                return true;

            case 'not':
                $expression = new OperatorNot($childExpression);
                return true;

            default:
                $type = null;
                return false;
        }
    }

    protected function getLengthFunction($argument, $hasZero, &$expression, &$type)
    {
        if (!$this->getExpression($argument, self::$REQUIRED, $childExpression, $argumentType)) {
            return false;
        }

        $type = Output::TYPE_INTEGER;
        $expression = new FunctionLength($childExpression);
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
        
        $type = self::getReturnTypeFromFunctionName($name, $argumentTypeOne, $argumentTypeTwo, false);

        switch ($name) {
            case 'plus':
                if ($argumentTypeOne === Output::TYPE_STRING) {
                    $expression = new FunctionConcatenate($expressionA, $expressionB);
                } else {
                    $expression = new OperatorPlus($expressionA, $expressionB);
                }
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
                $type = null;
                return false;
        }
    }

    protected function getTernaryFunction($name, $argumentA, $argumentB, $argumentC, $hasZero, &$expression, &$type)
    {
        if ($name === 'substring') {
            return $this->getSubstringFunction($argumentA, $argumentB, $argumentC, $hasZero, $expression, $type);
        }        
        
        if (
            !$this->getExpression($argumentA, $hasZero, $expressionA, $argumentTypeOne) ||
            !$this->getExpression($argumentB, $hasZero, $expressionB, $argumentTypeTwo) ||
            !$this->getExpression($argumentC, $hasZero, $expressionC, $argumentTypeThree)
        ) {

            return false;
        }
        
        $type = self::getReturnTypeFromFunctionName($name, $argumentTypeOne, $argumentTypeTwo, $argumentTypeThree);

        switch ($name) {
            default:
                $type = null;
                return false;
        }
    }

    protected function getSubstringFunction($argumentA, $argumentB, $argumentC, $hasZero, &$expression, &$type)
    {
        if (!$this->getExpression($argumentA, self::$REQUIRED, $expressionA, $typeA)) {
            return false;
        }

        if (
            !$this->scanParameter($argumentB, $nameB) ||
            !$this->scanParameter($argumentC, $nameC)
        ) {
            return false;
        }

        $idB = $this->input->useIncrementedArgument($nameB, self::$REQUIRED);
        $idC = $this->input->useSubtractiveArgument($nameB, $nameC, self::$REQUIRED);
        $parameterB = new Parameter($idB);
        $parameterC = new Parameter($idC);

        $type = Output::TYPE_STRING;
        $expression = new FunctionSubstring($expressionA, $parameterB, $parameterC);
        return true;
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
    
    protected static function getReturnTypeFromFunctionName($name, $typeOne, $typeTwo, $typeThree)
    {
        $allSignatures = Compiler::getSignatures();
        if (array_key_exists($name, $allSignatures)) {
            $signatures = $allSignatures[$name];
            
            foreach ($signatures as $signature) {
                if (self::signatureMatchesArguments($signature, $typeOne, $typeTwo, $typeThree)) {
                    return $signature['return'];
                }
            }
            
            // just return the return type of the first signature
            return $signatures[0]['return'];
        } else {
            return false;
        }
    }
    
    protected static function signatureMatchesArguments($signature, $typeOne, $typeTwo, $typeThree)
    {
        if ($signature['arguments'][0] !== $typeOne) {
            return false;
        }

        // TODO: assumes functions take at most 3 arguments for simplicity
        if (count($signature['arguments']) >= 2) {
            if ($signature['arguments'][1] !== $typeTwo) {
                return false;
            }

            if (count($signature['arguments']) >= 3) {
                return $signature['arguments'][2] === $typeThree;
            }
            
            return true;
        }

        return true;
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

    protected static function scanTable($input, &$table, &$id, &$hasZero)
    {
        // scan the next token of the supplied arrayToken
        $input = reset($input);

        list($tokenType, $token) = each($input);

        if ($tokenType !== Translator::TYPE_TABLE) {
            return false;
        }

        $table = $token['table'];
        $id = $token['id'];
        $hasZero = $token['hasZero'];

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
        reset($input);

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
