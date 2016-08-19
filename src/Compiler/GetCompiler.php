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
use Datto\Cinnabari\Mysql\Expression\AbstractExpression;
use Datto\Cinnabari\Mysql\Expression\Average;
use Datto\Cinnabari\Mysql\Expression\Boolean;
use Datto\Cinnabari\Mysql\Expression\Column;
use Datto\Cinnabari\Mysql\Expression\Count;
use Datto\Cinnabari\Mysql\Expression\Max;
use Datto\Cinnabari\Mysql\Expression\Min;
use Datto\Cinnabari\Mysql\Expression\Parameter;
use Datto\Cinnabari\Mysql\Expression\Sum;
use Datto\Cinnabari\Mysql\Expression\Table;
use Datto\Cinnabari\Mysql\Select;
use Datto\Cinnabari\Php\Input;
use Datto\Cinnabari\Php\Output;
use Datto\Cinnabari\Translator;

/**
 * Class GetCompiler
 * @package Datto\Cinnabari
 */
class GetCompiler extends AbstractCompiler
{
    /** @var Select */
    protected $mysql;
    
    /** @var Select */
    protected $subquery;

    /** @var String */
    private $phpOutput;
    
    public function compile($topLevelFunction, $translatedRequest, $types)
    {
        $optimizedRequest = self::optimize($topLevelFunction, $translatedRequest);
        $this->request = $optimizedRequest;

        $this->mysql = new Select();
        $this->subquery = null;
        $this->input = new Input($types);
        $this->phpOutput = null;

        if (!$this->enterTable($id, $hasZero)) {
            return null;
        }

        $this->getFunctionSequence($topLevelFunction, $id, $hasZero);

        $mysql = $this->mysql->getMysql();

        $formatInput = $this->input->getPhp();

        if (!isset($mysql, $formatInput, $this->phpOutput)) {
            return null;
        }

        return array($mysql, $formatInput, $this->phpOutput);
    }

    private function enterTable(&$id, &$hasZero)
    {
        $firstElement = array_shift($this->request);
        list(, $token) = each($firstElement);

        $this->context = $this->mysql->setTable(new Table($token['table']));
        $id = $token['id'];
        $hasZero = $token['hasZero'];

        return true;
    }

    protected function getFunctionSequence($topLevelFunction, $id, $hasZero)
    {
        $idAlias = null;
        if ($topLevelFunction === 'get') {
            $idAlias = $this->mysql->addValue($this->context, $id);
        }
            
        $this->getOptionalFilterFunction();
        $this->getOptionalSortFunction();
        $this->getOptionalSliceFunction();

        if (!isset($this->request) || count($this->request) === 0) {
            throw CompilerException::badGetArgument($this->request);
        }

        if ($this->readFork()) {
            return $this->getFunctionSequence($topLevelFunction, null, null);
        }

        $this->request = reset($this->request);

        if ($this->readGet()) {
            $this->phpOutput = Output::getList($idAlias, $hasZero, true, $this->phpOutput);

            return true;
        }

        if ($this->readCount()) {
            return true;
        }

        if ($this->readParameterizedAggregator($topLevelFunction)) {
            return true;
        }

        throw CompilerException::invalidMethodSequence($this->request);
    }

    protected function readFork()
    {
        if (!self::scanFunction(reset($this->request), $name, $arguments)) {
            return false;
        }

        if ($name !== 'fork') {
            return false;
        }

        array_shift($this->request);

        $this->subquery = $this->mysql;
        $this->mysql = new Select();
        $this->subqueryContext = $this->context;
        $this->context = $this->mysql->setTable($this->subquery);

        return true;
    }

    protected function getSubtractiveParameters($nameA, $nameB, &$outputA, &$outputB)
    {
        $idA = $this->input->useArgument($nameA, self::$REQUIRED);
        $idB = $this->input->useSubtractiveArgument($nameA, $nameB, self::$REQUIRED);

        if (($idA === null) || ($idB === null)) {
            return false;
        }

        $outputA = new Parameter($idA);
        $outputB = new Parameter($idB);
        return true;
    }

    protected function readExpression()
    {
        if (!isset($this->request) || (count($this->request) < 1)) {
            return false;
        }

        $firstElement = reset($this->request);
        list($tokenType, $token) = each($firstElement);

        if (!isset($token)) {
            return false;
        }

        switch ($tokenType) {
            case Translator::TYPE_JOIN:
                $this->setRollbackPoint();
                $this->handleJoin($token);
                array_shift($this->request);
                return $this->conditionallyRollback(
                    $this->readExpression()
                );

            case Translator::TYPE_VALUE:
                return $this->readProperty();

            case Translator::TYPE_OBJECT:
                return $this->readObject();

            case Translator::TYPE_FUNCTION:
                return $this->readFunction(); // any function

            default:
                return false;
        }
    }

    protected function readProperty()
    {
        $firstElement = reset($this->request);
        list(, $token) = each($firstElement);

        $actualType = $token['type'];
        $column = $token['expression'];
        $hasZero = $token['hasZero'];

        $tableId = $this->context;

        $columnId = $this->mysql->addValue($tableId, $column);
        $this->phpOutput = Output::getValue($columnId, $hasZero, $actualType);

        return true;
    }

    protected function readObject()
    {
        if (!self::scanObject($this->request, $object)) {
            return false;
        }

        $properties = array();

        $initialContext = $this->context;
        foreach ($object as $label => $this->request) {
            $this->context = $initialContext;
            if (!$this->readExpression()) {
                return false;
            }

            $properties[$label] = $this->phpOutput;
        }

        $this->phpOutput = Output::getObject($properties);
        return true;
    }

    protected function getGet($request)
    {
        $this->request = $request;

        if (!isset($this->contextJoin)) {
            throw CompilerException::badGetArgument($this->request);
        }

        return $this->getFunctionSequence(
            'get',
            $this->contextJoin['id'],
            $this->contextJoin['hasZero']
        );
    }

    protected function readGet()
    {
        if (!self::scanFunction($this->request, $name, $arguments)) {
            return false;
        }

        if ($name !== 'get') {
            return false;
        }

        if (!isset($arguments) || (count($arguments) !== 1)) {
            throw CompilerException::badGetArgument($this->request);
        }

        // at this point they definitely intend to use a get function
        $this->request = reset($arguments);

        if (!$this->readExpression()) {
            throw CompilerException::badGetArgument($this->request);
        }

        return true;
    }

    protected function readCount()
    {
        if (!self::scanFunction($this->request, $name, $arguments)) {
            return false;
        }

        if ($name !== 'count') {
            return false;
        }

        if (!isset($arguments) || (count($arguments) > 0)) {
            throw CompilerException::badGetArgument($this->request);
        }

        // at this point they definitely intend to use a count function
        $this->request = reset($arguments);

        $true = new Boolean(true);
        $expressionToCount = $true;
        if (isset($this->subquery)) {
            // select true in the subquery
            $expressionId = $this->subquery->addExpression($true);
            $columnToSelect = Select::getAbsoluteExpression(
                Select::getIdentifier($this->context),
                Select::getIdentifier($expressionId)
            );
            $expressionToCount = new Column($columnToSelect);
        }

        // select count in the main query
        $count = new Count($expressionToCount);
        $columnId = $this->mysql->addExpression($count);

        $this->phpOutput = Output::getValue($columnId, false, Output::TYPE_INTEGER);

        return true;
    }

    protected function readParameterizedAggregator($functionName)
    {
        if (!self::scanFunction($this->request, $name, $arguments)) {
            return false;
        }

        if (!isset($arguments) || (count($arguments) !== 1)) {
            throw CompilerException::badGetArgument($this->request);
        }

        // at this point they definitely intend to use a parameterized aggregator
        $this->request = reset($arguments);
        if (!isset($this->request) || (count($this->request) === 0)) {
            throw CompilerException::badGetArgument($this->request);
        }

        $this->request = $this->followJoins($this->request);
        if (!isset($this->request) || (count($this->request) === 0)) {
            throw CompilerException::badGetArgument($this->request);
        }

        $this->request = reset($this->request);
        if (!$this->scanProperty($this->request, $table, $name, $type, $hasZero)) {
            throw CompilerException::badGetArgument($this->request);
        }

        // get the aggregator's argument's corresponding column
        $tableId = $this->context;
        $tableAliasIdentifier = Select::getIdentifier($tableId);
        $columnExpression = Select::getAbsoluteExpression($tableAliasIdentifier, $name);
        $column = new Column($columnExpression);
        $expressionToAggregate = $column;

        // handle subqueries
        if (isset($this->subquery)) {
            // select true in the subquery
            $expressionId = $this->subquery->addExpression($column);
            $columnToSelect = Select::getAbsoluteExpression(
                Select::getIdentifier($this->context),
                Select::getIdentifier($expressionId)
            );
            $expressionToAggregate = new Column($columnToSelect);
        }

        switch ($functionName) {
            case 'average':
                $aggregator = new Average($expressionToAggregate);
                $type = Output::TYPE_FLOAT;
                break;

            case 'sum':
                $aggregator = new Sum($expressionToAggregate);
                break;

            case 'min':
                $aggregator = new Min($expressionToAggregate);
                break;

            case 'max':
                $aggregator = new Max($expressionToAggregate);
                break;

            default:
                throw CompilerException::unknownRequestType($functionName);
        }

        $columnId = $this->mysql->addExpression($aggregator);
        $this->phpOutput = Output::getValue($columnId, true, $type);

        return true;
    }

    protected function readFunction()
    {
        if (!self::scanFunction(reset($this->request), $name, $arguments)) {
            return false;
        }

        switch ($name) {
            case 'get':
                return $this->getGet($this->request);

            case 'uppercase':
            case 'lowercase':
            case 'substring':
            case 'length':
            case 'plus':
            case 'minus':
            case 'times':
            case 'divides':
                if (!$this->getExpression(
                    $this->request,
                    self::$REQUIRED,
                    $expression,
                    $type
                )) {
                    return false;
                }

                /** @var AbstractExpression $expression */
                $columnId = $this->mysql->addExpression($expression);

                $isNullable = true; // TODO: assumption
                $this->phpOutput = Output::getValue(
                    $columnId,
                    $isNullable,
                    $type
                );

                return true;

            default:
                return false;
        }
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
                if (isset($this->subquery) && $result) {
                    $subqueryValueId = $this->subquery->addValue(
                        $this->subqueryContext,
                        $token['expression']
                    );
                    $columnExpression = Select::getAbsoluteExpression(
                        Select::getIdentifier($this->context),
                        Select::getIdentifier($subqueryValueId)
                    );
                    $expression = new Column($columnExpression);
                }
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

    protected function getOptionalSortFunction()
    {
        if (!self::scanFunction(reset($this->request), $name, $arguments)) {
            return false;
        }

        if ($name !== 'sort') {
            return false;
        }

        // at this point, we're sure they want to sort
        if (!isset($arguments) || count($arguments) !== 1) {
            // TODO: add an explanation of the missing argument, or link to the documentation
            throw CompilerException::noSortArguments($this->request);
        }

        $state = array($this->request, $this->context);

        // consume all of the joins
        $this->request = $arguments[0];
        $this->request = $this->followJoins($this->request);

        if (!$this->scanProperty(reset($this->request), $table, $name, $type, $hasZero)) {
            return false;
        }

        if (isset($this->subquery)) {
            $name = Select::getIdentifier(
                $this->subquery->addValue($this->subqueryContext, $name)
            );
        }

        $this->mysql->setOrderBy($this->context, $name, true);

        list($this->request, $this->context) = $state;

        array_shift($this->request);

        return true;
    }

    protected function getOptionalSliceFunction()
    {
        if (!self::scanFunction(reset($this->request), $name, $arguments)) {
            return false;
        }

        if ($name !== 'slice') {
            return false;
        }

        // at this point, we're sure they want to slice
        if (!isset($arguments) || count($arguments) !== 2) {
            throw CompilerException::badSliceArguments($this->request);
        }

        if (
            !$this->scanParameter($arguments[0], $nameA) ||
            !$this->scanParameter($arguments[1], $nameB)
        ) {
            return false;
        }

        if (!$this->getSubtractiveParameters($nameA, $nameB, $start, $end)) {
            return false;
        }

        $this->mysql->setLimit($start, $end);

        array_shift($this->request);

        return true;
    }

    protected function getProperty($propertyToken, &$output, &$type)
    {
        $column = $propertyToken['expression'];
        $type = $propertyToken['type'];

        $tableId = $this->context;
        $tableAliasIdentifier = "`{$tableId}`";
        $columnExpression = Select::getAbsoluteExpression($tableAliasIdentifier, $column);
        $output = new Column($columnExpression);

        return true;
    }

    private static function scanObject($input, &$object)
    {
        // scan the next token of the supplied arrayToken
        $input = reset($input);
        list($tokenType, $token) = each($input);
        if ($tokenType !== Translator::TYPE_OBJECT) {
            return false;
        }
        $object = $token;
        return true;
    }
}
