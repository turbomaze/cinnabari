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
use Datto\Cinnabari\Mysql\Expression\AbstractExpression;
use Datto\Cinnabari\Mysql\Expression\Average;
use Datto\Cinnabari\Mysql\Expression\Boolean;
use Datto\Cinnabari\Mysql\Expression\Column;
use Datto\Cinnabari\Mysql\Expression\Count;
use Datto\Cinnabari\Mysql\Expression\Max;
use Datto\Cinnabari\Mysql\Expression\Min;
use Datto\Cinnabari\Mysql\Expression\Parameter;
use Datto\Cinnabari\Mysql\Expression\Sum;
use Datto\Cinnabari\Mysql\Select;
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

    /** @var String */
    private $phpOutput;
    
    public function compile($topLevelFunction, $translatedRequest, $arguments)
    {
        $this->request = $translatedRequest;

        $this->mysql = new Select();
        $this->arguments = new Arguments($arguments);
        $this->phpOutput = null;

        if (!$this->enterTable($id, $hasZero)) {
            return null;
        }

        $this->getFunctionSequence($topLevelFunction, $id, $hasZero);

        $mysql = $this->mysql->getMysql();

        $formatInput = $this->arguments->getPhp();

        if (!isset($mysql, $formatInput, $this->phpOutput)) {
            return null;
        }

        return array($mysql, $formatInput, $this->phpOutput);
    }

    private function enterTable(&$id, &$hasZero)
    {
        $firstElement = array_shift($this->request);
        list(, $token) = each($firstElement);

        $this->context = $this->mysql->setTable($token['table']);
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

        if (!isset($this->request) || (count($this->request) !== 1)) {
            throw CompilerException::badGetArgument($this->request);
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

    protected function getSubtractiveParameters($nameA, $nameB, $typeA, $typeB, &$outputA, &$outputB)
    {
        $idA = $this->arguments->useArgument($nameA, $typeA);
        $idB = $this->arguments->useSubtractiveArgument($nameA, $nameB, $typeA, $typeB);

        if (($idA === null) || ($idB === null)) {
            return false;
        }

        $outputA = new Parameter($idA);
        $outputB = new Parameter($idB);
        return true;
    }

    protected function readExpression()
    {
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

    protected function getGet($arguments)
    {
        $this->request = reset($arguments);
        return $this->readExpression();
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

        $count = new Count(new Boolean(true));
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

        // add the aggregator with its corresponding column
        $tableId = $this->context;
        $tableAliasIdentifier = "`{$tableId}`";
        $columnExpression = Select::getAbsoluteExpression($tableAliasIdentifier, $name);
        $column = new Column($columnExpression);

        switch ($functionName) {
            case 'average':
                $aggregator = new Average($column);
                break;

            case 'sum':
                $aggregator = new Sum($column);
                break;

            case 'min':
                $aggregator = new Min($column);
                break;

            case 'max':
                $aggregator = new Max($column);
                break;

            default:
                throw CompilerException::unknownRequestType($functionName);
        }

        $columnId = $this->mysql->addExpression($aggregator);
        $this->phpOutput = Output::getValue($columnId, false, Output::TYPE_INTEGER);

        return true;
    }

    protected function readFunction()
    {
        if (!self::scanFunction(reset($this->request), $name, $arguments)) {
            return false;
        }

        switch ($name) {
            case 'get':
                return $this->getGet($arguments);

            case 'plus':
            case 'minus':
            case 'times':
            case 'divides':
                if (!$this->getExpression(
                    $this->request,
                    $expression,
                    $type
                )) {
                    return false;
                }

                /** @var AbstractExpression $expression */
                $columnId = $this->mysql->addExpression($expression);

                $isNullable = true; // TODO
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

        if (!$this->getSubtractiveParameters($nameA, $nameB, 'integer', 'integer', $start, $end)) {
            return false;
        }

        $this->mysql->setLimit($start, $end);

        array_shift($this->request);

        return true;
    }

    protected function getProperty($propertyToken, $neededType, &$output)
    {
        $actualType = $propertyToken['type'];
        $column = $propertyToken['expression'];

        if ($neededType !== $actualType) {
            return false;
        }

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
