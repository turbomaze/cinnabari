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

namespace Datto\Cinnabari;

use Datto\Cinnabari\Compiler;
use Datto\Cinnabari\CompilerInterface;
use Datto\Cinnabari\Exception\AbstractException;
use Datto\Cinnabari\Format\Arguments;
use Datto\Cinnabari\Mysql\Expression\AbstractExpression;
use Datto\Cinnabari\Mysql\Expression\Column;
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
use Datto\Cinnabari\Mysql\Select;
use Datto\Cinnabari\Php\Output;

/**
 * Class GetCompiler
 * @package Datto\Cinnabari
 *
 * EBNF:
 *
 * request = list, [ filter-function ], map-function
 * map-argument = path | property | object | map
 * object-value = path | property | object | map
 */
class GetCompiler implements CompilerInterface
{
    /** @var Schema */
    private $schema;

    /** @var array */
    private $request;

    /** @var Arguments */
    private $arguments;

    /** @var Select */
    private $mysql;

    /** @var string */
    private $phpOutput;

    /** @var string */
    private $context;

    /** @var array */
    private $rollbackPoint;

    public function __construct()
    {
        $this->rollbackPoint = array();
    }

    public function compile($translatedRequest, $arguments)
    {
        $this->request = $translatedRequest;

        $this->mysql = new Select();
        $this->arguments = new Arguments($arguments);
        $this->phpOutput = null;

        if (!$this->enterTable($idAlias, $hasZero)) {
            return null;
        }

        $this->getFunctionSequence();

        $this->phpOutput = Output::getList($idAlias, $hasZero, true, $this->phpOutput);

        $mysql = $this->mysql->getMysql();

        $formatInput = $this->arguments->getPhp();

        if (!isset($mysql, $formatInput, $this->phpOutput)) {
            return null;
        }

        return array($mysql, $formatInput, $this->phpOutput);
    }

    private function enterTable(&$idAlias, &$hasZero)
    {
        $firstElement = array_shift($this->request);
        list($tokenType, $token) = each($firstElement);

        $this->context = $this->mysql->setTable($token['table']);
        $idAlias = $this->mysql->addValue($this->context, $token['id']);
        $hasZero = $token['hasZero'];

        return true;
    }

    private function getFunctionSequence()
    {
        $this->getOptionalFilterFunction();
        $this->getOptionalSortFunction();
        $this->getOptionalSliceFunction();

        $this->request = reset($this->request);

        if (!$this->readMap()) {
            throw new AbstractException(
                Compiler::ERROR_NO_MAP_FUNCTION,
                array('request' => $this->request),
                "API requests must contain a map function after the optional filter/sort functions."
            );
        }

        return true;
    }

    private function getOptionalFilterFunction()
    {
        if (!self::scanFunction(reset($this->request), $name, $arguments)) {
            return false;
        }

        if ($name !== 'filter') {
            return false;
        }

        // at this point, we're sure they want to filter
        if (!isset($arguments) || count($arguments) === 0) {
            throw new AbstractException(
                Compiler::ERROR_NO_FILTER_ARGUMENTS,
                array('token' => $token),
                "filter functions take one expression argument, none provided."
            );
        }

        // TODO: throw exception for bad filters
        if (!$this->getBooleanExpression($arguments[0], $where)) {
            throw new AbstractException(
                Compiler::ERROR_BAD_FILTER_EXPRESSION,
                array(
                    'context' => $this->context,
                    'arguments' => $arguments[0]
                ),
                "malformed expression supplied to the filter function."
            );
        }

        $this->mysql->setWhere($where);

        array_shift($this->request);

        return true;
    }

    private function getExpression($arrayToken, &$expression, &$type)
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
                $this->handleJoin($token, $output);
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

    private function getBooleanParameter($name, &$output)
    {
        return $this->getParameter($name, 'boolean', $output);
    }

    private function getParameter($name, $type, &$output)
    {
        $id = $this->arguments->useArgument($name, $type);

        if ($id === null) {
            return false;
        }

        $output = new Parameter($id);
        return true;
    }

    private function getSubtractiveParameters($nameA, $nameB, $typeA, $typeB, &$outputA, &$outputB)
    {
        $idA = $this->arguments->useArgument($nameA, $typeA);
        $idB = $this->arguments->useSubtractiveArgument($nameA, $nameB, $typeA, $typeB);

        if ($idA === null || $idB === null) {
            return false;
        }

        $outputA = new Parameter($idA);
        $outputB = new Parameter($idB);
        return true;
    }

    private function getBooleanProperty($propertyToken, &$output)
    {
        return $this->getProperty($propertyToken, Output::TYPE_BOOLEAN, $output);
    }

    private function getBooleanFunction($name, $arguments, &$output)
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

    private function getBooleanUnaryFunction($name, $argument, &$expression)
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

    private function getBooleanBinaryFunction($name, $argumentA, $argumentB, &$expression)
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

    private function getEqualFunction($argumentA, $argumentB, &$expression)
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

    private function getAndFunction($argumentA, $argumentB, &$expression)
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

    private function getOrFunction($argumentA, $argumentB, &$expression)
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

    private function getNotEqualFunction($argumentA, $argumentB, &$expression)
    {
        if (!$this->getEqualFunction($argumentA, $argumentB, $equalExpression)) {
            return false;
        }

        $expression = new OperatorNot($equalExpression);
        return true;
    }

    private function getLessFunction($argumentA, $argumentB, &$expression)
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

    private function getLessEqualFunction($argumentA, $argumentB, &$expression)
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

    private function getGreaterFunction($argumentA, $argumentB, &$expression)
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

    private function getGreaterEqualFunction($argumentA, $argumentB, &$expression)
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

    private function getMatchFunction($property, $pattern, &$expression)
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

    private function getNumericExpression($arrayToken, &$output, &$type)
    {
        $firstElement = reset($arrayToken);
        list($tokenType, $token) = each($firstElement);

        $context = $this->context;
        $result = false;

        switch ($tokenType) {
            case Translator::TYPE_JOIN:
                $this->setRollbackPoint();
                $this->handleJoin($token, $output);
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

    private function getNumericParameter($name, &$output, &$type)
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

    private function getNumericProperty($propertyToken, &$output, &$type)
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

    private function getNumericBinaryFunction($name, $argumentA, $argumentB, &$expression, &$type)
    {
        if (
            !$this->getNumericExpression($argumentA, $expressionA, $typeA) ||
            !$this->getNumericExpression($argumentB, $expressionB, $typeB)
        ) {
            return false;
        }

        $aIsAnInteger = $typeA === Output::TYPE_INTEGER;
        $bIsAnInteger = $typeB === Output::TYPE_INTEGER;
        $aIsAFloat = $typeA === Output::TYPE_FLOAT;
        $bIsAFloat = $typeB === Output::TYPE_FLOAT;

        if ($name === 'plus' || $name === 'minus' || $name === 'times' || $name === 'divides') {
            if ($aIsAnInteger && $bIsAnInteger) {
                $type = Output::TYPE_INTEGER;
            } elseif ($aIsAnInteger && $bIsAFloat || $aIsAFloat && $bIsAnInteger || $aIsAFloat && $bIsAFloat) {
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

    private function getStringPropertyExpression($token, &$output)
    {
        if (count($token) < 2) {
            return false;
        }

        $type = $token[0];

        switch ($type) {
            case Parser::TYPE_PROPERTY:
                return $this->getStringProperty($token[1], $output);

            default:
                return false;
        }
    }

    private function getStringExpression($arrayToken, &$output)
    {
        $firstElement = reset($arrayToken);
        list($tokenType, $token) = each($firstElement);

        $context = $this->context;
        $result = false;

        switch ($tokenType) {
            case Translator::TYPE_JOIN:
                $this->setRollbackPoint();
                $this->handleJoin($token, $output);
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

    private function getStringParameter($name, &$output)
    {
        return $this->getParameter($name, 'string', $output);
    }

    private function getStringProperty($propertyToken, &$output)
    {
        return $this->getProperty($propertyToken, Output::TYPE_STRING, $output);
    }

    private function getProperty($propertyToken, $neededType, &$output)
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

    private function readExpression()
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

    private function handleJoin($token)
    {
        $this->context = $this->mysql->addJoin(
            $this->context,
            $token['tableB'],
            $token['expression'],
            $token['hasZero'],
            $token['hasMany']
        );
    }

    private function followJoins($arrayToken)
    {
        // consume all of the joins
        while ($this->scanJoin(reset($arrayToken), $joinToken)) {
            $this->handleJoin($joinToken);
            array_shift($arrayToken);
        }
        return $arrayToken;
    }

    private function readProperty()
    {
        $firstElement = reset($this->request);
        list($tokenType, $token) = each($firstElement);

        $actualType = $token['type'];
        $column = $token['expression'];
        $hasZero = $token['hasZero'];

        $tableId = $this->context;
        $tableAliasIdentifier = "`{$tableId}`";
        $columnExpression = Select::getAbsoluteExpression($tableAliasIdentifier, $column);

        $columnId = $this->mysql->addValue($tableId, $column);
        $this->phpOutput = Output::getValue($columnId, $hasZero, $actualType);

        return true;
    }

    private function readObject()
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

    private function getMap($arguments)
    {
        $this->request = reset($arguments);
        return $this->readExpression();
    }

    private function readMap()
    {
        if (!self::scanFunction($this->request, $name, $arguments)) {
            return false;
        }

        if ($name !== 'map') {
            return false;
        }

        // at this point they definitely intend to use a map function
        $this->request = reset($arguments);

        if (!$this->readExpression()) {
            throw new AbstractException(
                Compiler::ERROR_BAD_MAP_ARGUMENT,
                array('request' => $this->request),
                'map functions take a property, path, object, or function as an argument.'
            );
        }

        return true;
    }

    private function readFunction()
    {
        if (!self::scanFunction(reset($this->request), $name, $arguments)) {
            return false;
        }

        switch ($name) {
            case 'map':
                return $this->getMap($arguments);

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
                $columnId = $this->mysql->addExpression(
                    $this->context,
                    $expression->getMysql()
                );
                $nullable = true; // TODO
                $this->phpOutput = Output::getValue(
                    $columnId,
                    $nullable,
                    $type
                );

                return true;

            default:
                return false;
        }
    }

    private function getOptionalSortFunction()
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
            throw new AbstractException(
                Compiler::ERROR_NO_SORT_ARGUMENTS,
                array('token' => $token),
                "sort functions take one argument"
            );
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

    private function getOptionalSliceFunction()
    {
        if (!self::scanFunction(reset($this->request), $name, $arguments)) {
            return false;
        }

        if ($name !== 'slice') {
            return false;
        }

        // at this point, we're sure they want to slice
        if (!isset($arguments) || count($arguments) !== 2) {
            throw new AbstractException(
                Compiler::ERROR_NO_SORT_ARGUMENTS, // TODO: slice exception
                array('token' => reset($this->request)),
                "slice functions take two argument"
            );
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

        $this->mysql->setLimit($this->context, $start, $end);

        array_shift($this->request);

        return true;
    }

    private function conditionallyRollback($doRollback)
    {
        if ($doRollback) {
            $this->clearRollbackPoint();
            return true;
        } else {
            $this->rollback();
            return false;
        }
    }

    private function setRollbackPoint()
    {
        $this->rollbackPoint[] = array($this->context);
        $this->mysql->setRollbackPoint();
    }

    private function clearRollbackPoint()
    {
        array_pop($this->rollbackPoint);
        $this->mysql->clearRollbackPoint();
    }

    private function rollback()
    {
        $rollbackState = array_pop($this->rollbackPoint);
        $this->context = $rollbackState[0];
        $this->mysql->rollback();
    }

    private static function scanParameter($input, &$name)
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

    private static function scanProperty($input, &$table, &$name, &$type, &$hasZero)
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

    private static function scanFunction($input, &$name, &$arguments)
    {
        list($tokenType, $token) = each($input);

        if ($tokenType !== Translator::TYPE_FUNCTION) {
            return false;
        }

        $name = $token['function'];
        $arguments = $token['arguments'];
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

    private static function scanJoin($input, &$object)
    {
        list($tokenType, $token) = each($input);

        if ($tokenType !== Translator::TYPE_JOIN) {
            return false;
        }

        $object = $token;
        return true;
    }
}
