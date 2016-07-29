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
 * Class Compiler
 * @package Datto\Cinnabari
 *
 * EBNF:
 *
 * request = list, [ filter-function ], map-function
 * map-argument = path | property | object | map
 * object-value = path | property | object | map
 */
class Compiler
{
    // compiler errors
    const ERROR_NO_INITIAL_PROPERTY = 501;
    const ERROR_NO_INITIAL_PATH = 502;
    const ERROR_NO_MAP_FUNCTION = 503;
    const ERROR_NO_FILTER_ARGUMENTS = 504;
    const ERROR_BAD_FILTER_EXPRESSION = 505;
    const ERROR_NO_SORT_ARGUMENTS = 506;
    const ERROR_BAD_MAP_ARGUMENT = 507;
    const ERROR_BAD_SCHEMA = 508;

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
    private $class;

    /** @var int */
    private $table;

    public function __construct(Schema $schema)
    {
        $this->schema = $schema;
    }

    public function compile($request, $arguments)
    {
        $this->request = $request;

        $this->mysql = new Select();
        $this->arguments = new Arguments($arguments);
        $this->phpOutput = null;

        if (!$this->getArrayProperty()) {
            return null;
        }

        $mysql = $this->mysql->getMysql();

        $formatInput = $this->arguments->getPhp();

        if (!isset($mysql, $formatInput, $this->phpOutput)) {
            return null;
        }

        return array($mysql, $formatInput, $this->phpOutput);
    }

    private function getArrayProperty()
    {
        if (!self::scanPath($this->request, $this->request)) {
            throw new AbstractException(
                self::ERROR_NO_INITIAL_PATH,
                array('request' => $this->request),
                "API requests must begin with a path."
            );
        }

        $token = array_shift($this->request);

        if (!self::scanProperty($token, $array)) {
            throw new AbstractException(
                self::ERROR_NO_INITIAL_PROPERTY,
                array('token' => $token),
                "API requests must begin with a property."
            );
        }

        list($class, $path) = $this->schema->getPropertyDefinition('Database', $array);

        if (!isset($class, $path)) {
            throw new AbstractException(
                self::ERROR_BAD_SCHEMA,
                array(
                    'accessType' => 'property',
                    'arguments' => array($array)
                ),
                "schema failed to return a proper property definition."
            );
        }

        $list = array_pop($path);

        list($table, $id, $hasZero) = $this->schema->getListDefinition($list);

        if (!isset($table, $id, $hasZero)) {
            throw new AbstractException(
                self::ERROR_BAD_SCHEMA,
                array(
                    'accessType' => 'list',
                    'arguments' => array($list)
                ),
                "schema failed to return a proper list definition."
            );
        }

        $this->class = $class;
        $this->table = $this->mysql->setTable($table);

        $idAlias = $this->mysql->addValue($this->table, $id);
        $this->connections($this->table, $table, $path);

        $this->getOptionalFilterFunction();
        $this->getOptionalSortFunction();
        $this->getOptionalSliceFunction();

        $this->request = reset($this->request);

        if (!$this->readMap()) {
            throw new AbstractException(
                self::ERROR_NO_MAP_FUNCTION,
                array('request' => $this->request),
                "API requests must contain a map function after the optional filter/sort functions."
            );
        }

        $this->phpOutput = Output::getList($idAlias, $hasZero, true, $this->phpOutput);
        return true;
    }

    private function getOptionalFilterFunction()
    {
        $token = current($this->request);

        if (!self::scanFunction($token, $name, $arguments)) {
            return false;
        }

        if ($name !== 'filter') {
            return false;
        }

        // at this point, we're sure they want to filter
        if (!isset($arguments) || count($arguments) === 0) {
            throw new AbstractException(
                self::ERROR_NO_FILTER_ARGUMENTS,
                array('token' => $token),
                "filter functions take one expression argument, none provided."
            );
        }

        if (!$this->getExpression($this->class, $this->table, $arguments[0], $where, $type)) {
            throw new AbstractException(
                self::ERROR_BAD_FILTER_EXPRESSION,
                array(
                    'class' => $this->class,
                    'table' => $this->table,
                    'arguments' => $arguments[0]
                ),
                "malformed expression supplied to the filter function."
            );
        }

        $this->mysql->setWhere($where);

        array_shift($this->request);
        return true;
    }

    private function getExpression($class, $tableId, $token, &$expression, &$type)
    {
        return $this->getBooleanExpression($class, $tableId, $token, $expression)
            || $this->getNumericExpression($class, $tableId, $token, $expression, $type)
            || $this->getStringExpression($class, $tableId, $token, $expression);
    }

    private function getBooleanExpression($class, $tableId, $token, &$output)
    {
        list($type, $name) = $token;

        switch ($type) {
            case Parser::TYPE_PATH:
                $tokens = array_slice($token, 1);
                return $this->getBooleanPath($class, $tableId, $tokens, $output);

            case Parser::TYPE_PARAMETER:
                return $this->getBooleanParameter($name, $output);

            case Parser::TYPE_PROPERTY:
                return $this->getBooleanProperty($class, $tableId, $name, $output);

            case Parser::TYPE_FUNCTION:
                $arguments = array_slice($token, 2);
                return $this->getBooleanFunction($class, $tableId, $name, $arguments, $output);

            default:
                return false;
        }
    }

    private function getBooleanPath($class, $tableId, $tokens, &$output)
    {
        $token = reset($tokens);

        if (!self::scanProperty($token, $property)) {
            return false;
        }

        array_shift($tokens);

        list($class, $path) = $this->schema->getPropertyDefinition($class, $property);

        if (!isset($class, $path)) {
            return false;
        }

        $tableIdentifier = $this->mysql->getTable($tableId);
        $this->connections($tableId, $tableIdentifier, $path);

        if (count($tokens) === 1) {
            $request = array_shift($tokens);
        } else {
            array_unshift($tokens, Parser::TYPE_PATH);
            $request = $tokens;
        }

        return $this->getBooleanExpression($class, $tableId, $request, $output);
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
        list($idA, $idB) = $this->arguments->useSubtractiveArgument($nameA, $nameB, $typeA, $typeB);

        if ($idA === null || $idB === null) {
            return false;
        }

        $outputA = new Parameter($idA);
        $outputB = new Parameter($idB);
        return true;
    }

    private function getBooleanProperty($class, $tableId, $name, &$output)
    {
        return $this->getProperty($class, $tableId, $name, Output::TYPE_BOOLEAN, $output);
    }

    private function getBooleanFunction($class, $tableId, $name, $arguments, &$output)
    {
        $countArguments = count($arguments);

        if ($countArguments === 1) {
            $argument = current($arguments);
            return $this->getBooleanUnaryFunction($class, $tableId, $name, $argument, $output);
        }

        if ($countArguments === 2) {
            list($argumentA, $argumentB) = $arguments;
            return $this->getBooleanBinaryFunction($class, $tableId, $name, $argumentA, $argumentB, $output);
        }

        return false;
    }

    private function getBooleanUnaryFunction($class, $tableId, $name, $argument, &$expression)
    {
        if ($name !== 'not') {
            return false;
        }

        if (!$this->getBooleanExpression($class, $tableId, $argument, $childExpression)) {
            return false;
        }

        $expression = new OperatorNot($childExpression);
        return true;
    }

    private function getBooleanBinaryFunction($class, $tableId, $name, $argumentA, $argumentB, &$expression)
    {
        switch ($name) {
            case 'equal':
                return $this->getEqualFunction($class, $tableId, $argumentA, $argumentB, $expression);

            case 'and':
                return $this->getAndFunction($class, $tableId, $argumentA, $argumentB, $expression);

            case 'or':
                return $this->getOrFunction($class, $tableId, $argumentA, $argumentB, $expression);

            case 'notEqual':
                return $this->getNotEqualFunction($class, $tableId, $argumentA, $argumentB, $expression);

            case 'less':
                return $this->getLessFunction($class, $tableId, $argumentA, $argumentB, $expression);

            case 'lessEqual':
                return $this->getLessEqualFunction($class, $tableId, $argumentA, $argumentB, $expression);

            case 'greater':
                return $this->getGreaterFunction($class, $tableId, $argumentA, $argumentB, $expression);

            case 'greaterEqual':
                return $this->getGreaterEqualFunction($class, $tableId, $argumentA, $argumentB, $expression);

            case 'match':
                return $this->getMatchFunction($class, $tableId, $argumentA, $argumentB, $expression);

            default:
                return false;
        }
    }

    private function getEqualFunction($class, $tableId, $argumentA, $argumentB, &$expression)
    {
        if (
            (
                $this->getBooleanExpression($class, $tableId, $argumentA, $expressionA) &&
                $this->getBooleanExpression($class, $tableId, $argumentB, $expressionB)
            ) || (
                $this->getNumericExpression($class, $tableId, $argumentA, $expressionA, $typeA) &&
                $this->getNumericExpression($class, $tableId, $argumentB, $expressionB, $typeB)
            ) || (
                $this->getStringExpression($class, $tableId, $argumentA, $expressionA) &&
                $this->getStringExpression($class, $tableId, $argumentB, $expressionB)
            )
        ) {
            $expression = new OperatorEqual($expressionA, $expressionB);
            return true;
        }

        return false;
    }

    private function getAndFunction($class, $tableId, $argumentA, $argumentB, &$expression)
    {
        if (
            !$this->getBooleanExpression($class, $tableId, $argumentA, $outputA) ||
            !$this->getBooleanExpression($class, $tableId, $argumentB, $outputB)
        ) {
            return false;
        }

        $expression = new OperatorAnd($outputA, $outputB);
        return true;
    }

    private function getOrFunction($class, $tableId, $argumentA, $argumentB, &$expression)
    {
        if (
            !$this->getBooleanExpression($class, $tableId, $argumentA, $outputA) ||
            !$this->getBooleanExpression($class, $tableId, $argumentB, $outputB)
        ) {
            return false;
        }

        $expression = new OperatorOr($outputA, $outputB);
        return true;
    }

    private function getNotEqualFunction($class, $tableId, $argumentA, $argumentB, &$expression)
    {
        if (!$this->getEqualFunction($class, $tableId, $argumentA, $argumentB, $equalExpression)) {
            return false;
        }

        $expression = new OperatorNot($equalExpression);
        return true;
    }

    private function getLessFunction($class, $tableId, $argumentA, $argumentB, &$expression)
    {
        if (
            (
                $this->getNumericExpression($class, $tableId, $argumentA, $expressionA, $typeA) &&
                $this->getNumericExpression($class, $tableId, $argumentB, $expressionB, $typeB)
            ) || (
                $this->getStringExpression($class, $tableId, $argumentA, $expressionA) &&
                $this->getStringExpression($class, $tableId, $argumentB, $expressionB)
            )
        ) {
            $expression = new OperatorLess($expressionA, $expressionB);
            return true;
        }

        return false;
    }

    private function getLessEqualFunction($class, $tableId, $argumentA, $argumentB, &$expression)
    {
        if (
            (
                $this->getNumericExpression($class, $tableId, $argumentA, $expressionA, $typeA) &&
                $this->getNumericExpression($class, $tableId, $argumentB, $expressionB, $typeB)
            ) || (
                $this->getStringExpression($class, $tableId, $argumentA, $expressionA) &&
                $this->getStringExpression($class, $tableId, $argumentB, $expressionB)
            )
        ) {
            $expression = new OperatorLessEqual($expressionA, $expressionB);
            return true;
        }

        return false;
    }

    private function getGreaterFunction($class, $tableId, $argumentA, $argumentB, &$expression)
    {
        if (
            (
                $this->getNumericExpression($class, $tableId, $argumentA, $expressionA, $typeA) &&
                $this->getNumericExpression($class, $tableId, $argumentB, $expressionB, $typeB)
            ) || (
                $this->getStringExpression($class, $tableId, $argumentA, $expressionA) &&
                $this->getStringExpression($class, $tableId, $argumentB, $expressionB)
            )
        ) {
            $expression = new OperatorGreater($expressionA, $expressionB);
            return true;
        }

        return false;
    }

    private function getGreaterEqualFunction($class, $tableId, $argumentA, $argumentB, &$expression)
    {
        if (
            (
                $this->getNumericExpression($class, $tableId, $argumentA, $expressionA, $typeA) &&
                $this->getNumericExpression($class, $tableId, $argumentB, $expressionB, $typeB)
            ) || (
                $this->getStringExpression($class, $tableId, $argumentA, $expressionA) &&
                $this->getStringExpression($class, $tableId, $argumentB, $expressionB)
            )
        ) {
            $expression = new OperatorGreaterEqual($expressionA, $expressionB);
            return true;
        }

        return false;
    }

    private function getMatchFunction($class, $tableId, $property, $pattern, &$expression)
    {
        if (!isset($property) || count($property) < 2) {
            return false;
        }
        
        if ($property[0] === Parser::TYPE_PATH) {
            $tokens = array_slice($property, 1);
            if (!$this->getStringPath($class, $tableId, $tokens, $argumentExpression, Parser::TYPE_PROPERTY)) {
                return false;
            }
        } else {
            if (!$this->getStringProperty($class, $tableId, $property[1], $argumentExpression)) {
                return false;
            }
        }

        if (($pattern[0] !== Parser::TYPE_PARAMETER) || !$this->getStringParameter($pattern[1], $patternExpression)) {
            return false;
        }

        $expression = new OperatorRegexpBinary($argumentExpression, $patternExpression);
        return true;
    }

    private function getNumericExpression($class, $tableId, $token, &$output, &$type)
    {
        $type = $token[0];

        switch ($type) {
            case Parser::TYPE_PATH:
                $tokens = array_slice($token, 1);
                return $this->getNumericPath($class, $tableId, $tokens, $output, $type);

            case Parser::TYPE_PARAMETER:
                return $this->getNumericParameter($token[1], $output, $type);

            case Parser::TYPE_PROPERTY:
                return $this->getNumericProperty($class, $tableId, $token[1], $output, $type);

            case Parser::TYPE_FUNCTION:
                if (!self::scanFunction($token, $name, $arguments)) {
                    return false;
                }

                if (count($arguments) < 2) {
                    return false;
                }

                return $this->getNumericBinaryFunction($class, $tableId, $name, $arguments[0], $arguments[1], $output, $type);

            default:
                return false;
        }
    }

    private function getNumericPath($class, $tableId, $tokens, &$output, &$type)
    {
        $token = reset($tokens);

        if (!self::scanProperty($token, $property)) {
            return false;
        }

        array_shift($tokens);

        list($class, $path) = $this->schema->getPropertyDefinition($class, $property);

        if (!isset($class, $path)) {
            return false;
        }

        $tableIdentifier = $this->mysql->getTable($tableId);
        $this->connections($tableId, $tableIdentifier, $path);

        if (count($tokens) === 1) {
            $request = array_shift($tokens);
        } else {
            array_unshift($tokens, Parser::TYPE_PATH);
            $request = $tokens;
        }

        return $this->getNumericExpression($class, $tableId, $request, $output, $type);
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

    private function getNumericProperty($class, $tableId, $name, &$output, &$type)
    {
        if ($this->getProperty($class, $tableId, $name, Output::TYPE_INTEGER, $output)) {
            $type = Output::TYPE_INTEGER;
            return true;
        } elseif ($this->getProperty($class, $tableId, $name, Output::TYPE_FLOAT, $output)) {
            $type = Output::TYPE_FLOAT;
            return true;
        } else {
            return false;
        }
    }

    private function getNumericBinaryFunction($class, $tableId, $name, $argumentA, $argumentB, &$expression, &$type)
    {
        if (
            !$this->getNumericExpression($class, $tableId, $argumentA, $expressionA, $typeA) ||
            !$this->getNumericExpression($class, $tableId, $argumentB, $expressionB, $typeB)
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

    private function getStringPropertyExpression($class, $tableId, $token, &$output)
    {
        if (count($token) < 2) {
            return false;
        }

        $type = $token[0];

        switch ($type) {
            case Parser::TYPE_PATH:
                $tokens = array_slice($token, 1);
                return $this->getStringPath($class, $tableId, $tokens, $output, Parser::TYPE_PROPERTY);

            case Parser::TYPE_PROPERTY:
                return $this->getStringProperty($class, $tableId, $token[1], $output);

            default:
                return false;
        }
    }

    private function getStringExpression($class, $tableId, $token, &$output)
    {
        if (count($token) < 2) {
            return false;
        }

        $type = $token[0];

        switch ($type) {
            case Parser::TYPE_PATH:
                $tokens = array_slice($token, 1);
                return $this->getStringPath($class, $tableId, $tokens, $output);

            case Parser::TYPE_PARAMETER:
                return $this->getStringParameter($token[1], $output);

            case Parser::TYPE_PROPERTY:
                return $this->getStringProperty($class, $tableId, $token[1], $output);

            default:
                return false;
        }
    }

    private function getStringPath($class, $tableId, $tokens, &$output, $type = null)
    {
        $token = reset($tokens);

        if (!self::scanProperty($token, $property)) {
            return false;
        }

        array_shift($tokens);

        list($class, $path) = $this->schema->getPropertyDefinition($class, $property);

        if (!isset($class, $path)) {
            return false;
        }

        $tableIdentifier = $this->mysql->getTable($tableId);
        $this->connections($tableId, $tableIdentifier, $path);

        if (count($tokens) === 1) {
            $request = array_shift($tokens);
        } else {
            array_unshift($tokens, Parser::TYPE_PATH);
            $request = $tokens;
        }

        if ($type === Parser::TYPE_PROPERTY) {
            return $this->getStringPropertyExpression($class, $tableId, $request, $output);
        } else {
            return $this->getStringExpression($class, $tableId, $request, $output);
        }
    }

    private function getStringParameter($name, &$output)
    {
        return $this->getParameter($name, 'string', $output);
    }

    private function getStringProperty($class, $tableId, $name, &$output)
    {
        return $this->getProperty($class, $tableId, $name, Output::TYPE_STRING, $output);
    }

    private function getProperty($class, $tableId, $name, $neededType, &$output)
    {
        $tableIdentifier = $this->mysql->getTable($tableId);

        list($actualType, $path) = $this->schema->getPropertyDefinition($class, $name);

        if (!isset($actualType, $path)) {
            return false;
        }

        if ($neededType !== $actualType) {
            return false;
        }

        $value = array_pop($path);

        list($column, ) = $this->schema->getValueDefinition($tableIdentifier, $value);

        if (!isset($column)) {
            return false;
        }

        $this->connections($tableId, $tableIdentifier, $path);

        $tableAliasIdentifier = "`{$tableId}`";
        $columnExpression = Select::getAbsoluteExpression($tableAliasIdentifier, $column);
        $output = new Column($columnExpression);

        return true;
    }

    private function readExpressionAndGetTail(&$tailProperty)
    {
        $token = $this->request;

        $type = $token[0];

        switch ($type) {
            case Parser::TYPE_PATH:
                return $this->readPathAndGetTail($tailProperty);

            case Parser::TYPE_PROPERTY:
                return $this->readPropertyAndGetColumn($tailProperty);

            default:
                return false;
        }
    }

    private function readExpression()
    {
        $token = $this->request;

        if (!isset($token) || count($token) < 1) {
            return false;
        }

        $type = $token[0];

        switch ($type) {
            case Parser::TYPE_PATH:
                return $this->readPath();

            case Parser::TYPE_PROPERTY:
                return $this->readProperty();

            case Parser::TYPE_OBJECT:
                return $this->readObject();

            case Parser::TYPE_FUNCTION:
                return $this->readFunction(); // any function

            default:
                return false;
        }
    }

    private function readPathAndGetTail(&$tailProperty)
    {
        if (!self::scanPath($this->request, $tokens)) {
            return false;
        }

        $token = reset($tokens);

        if (!self::scanProperty($token, $property)) {
            return false;
        }

        array_shift($tokens);

        list($this->class, $path) = $this->schema->getPropertyDefinition($this->class, $property);

        $tableIdentifier = $this->mysql->getTable($this->table);
        $this->connections($this->table, $tableIdentifier, $path);
        // TODO

        if (count($tokens) === 1) {
            $this->request = array_shift($tokens);
        } else {
            array_unshift($tokens, Parser::TYPE_PATH);
            $this->request = $tokens;
        }

        return $this->readExpressionAndGetTail($tailProperty);
    }

    private function readPath()
    {
        if (!self::scanPath($this->request, $tokens)) {
            return false;
        }

        $token = reset($tokens);

        if (!self::scanProperty($token, $property)) {
            return false;
        }

        array_shift($tokens);

        list($this->class, $path) = $this->schema->getPropertyDefinition($this->class, $property);

        if (!isset($this->class, $path)) {
            return false;
        }

        $tableIdentifier = $this->mysql->getTable($this->table);
        $this->connections($this->table, $tableIdentifier, $path);
        // TODO

        if (count($tokens) === 1) {
            $this->request = array_shift($tokens);
        } else {
            array_unshift($tokens, Parser::TYPE_PATH);
            $this->request = $tokens;
        }

        return $this->readExpression();
    }

    private function readPropertyAndGetColumn(&$tailProperty)
    {
        if (!self::scanProperty($this->request, $property)) {
            return false;
        }

        list(, $path) = $this->schema->getPropertyDefinition($this->class, $property);

        $value = array_pop($path);

        // just get the table id; NOTE: this method does not add the property to the output!
        $tableIdentifier = $this->mysql->getTable($this->table);
        $this->connections($this->table, $tableIdentifier, $path);

        list($column,) = $this->schema->getValueDefinition($tableIdentifier, $value);

        $tailProperty = $column;

        return true;
    }

    private function readProperty()
    {
        if (!self::scanProperty($this->request, $property)) {
            return false;
        }

        list($type, $path) = $this->schema->getPropertyDefinition($this->class, $property);

        if (!isset($type, $path)) {
            return false;
        }

        $value = array_pop($path);

        $tableIdentifier = $this->mysql->getTable($this->table);
        $this->connections($this->table, $tableIdentifier, $path);

        list($column, $isColumnNullable) = $this->schema->getValueDefinition($tableIdentifier, $value);

        if (!isset($column, $isColumnNullable)) {
            return false;
        }

        $columnId = $this->mysql->addValue($this->table, $column);
        $this->phpOutput = Output::getValue($columnId, $isColumnNullable, $type);

        return true;
    }

    private function readObject()
    {
        if (!self::scanObject($this->request, $object)) {
            return false;
        }

        $properties = array();

        $class = $this->class;
        $table = $this->table;

        foreach ($object as $label => $this->request) {
            if (!$this->readExpression()) {
                return false;
            }

            $properties[$label] = $this->phpOutput;

            $this->class = $class;
            $this->table = $table;
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
                self::ERROR_BAD_MAP_ARGUMENT,
                array('request' => $this->request),
                'map functions take a property, path, object, or function as an argument.'
            );
        }

        return true;
    }

    private function readFunction()
    {
        if (!self::scanFunction($this->request, $name, $arguments)) {
            return false;
        }

        switch ($name) {
            case 'map':
                return $this->getMap($arguments);

            case 'plus':
            case 'minus':
            case 'times':
            case 'divides':
                if (!$this->getExpression($this->class, $this->table,
                    $this->request, $expression, $type)
                ) {
                    return false;
                }

                /** @var AbstractExpression $expression */
                $columnId = $this->mysql->addExpression($this->table,
                    $expression->getMysql());
                $nullable = true; // TODO
                $this->phpOutput = Output::getValue($columnId, $nullable,
                    $type);

                return true;

            default:
                return false;
        }
    }

    private function connections(&$contextId, &$tableAIdentifier, $connections)
    {
        foreach ($connections as $key => $connection) {
            $definition = $this->schema->getConnectionDefinition($tableAIdentifier, $connection);

            if (!isset($definition) || count($definition) < 5) {
                return false;
            }

            list($tableBIdentifier, $expression, $id, $allowsZeroMatches, $allowsMultipleMatches) = $definition;

            if (!$allowsZeroMatches && !$allowsMultipleMatches) {
                $joinType = Select::JOIN_INNER;
            } else {
                $joinType = Select::JOIN_LEFT;

                if ($this->phpOutput !== null) {
                    $idAlias = $this->mysql->addValue($this->table, $id);
                    $this->phpOutput = Output::getList($idAlias, $allowsZeroMatches, $allowsMultipleMatches, $this->phpOutput);
                }
            }

            $contextId = $this->mysql->addJoin($contextId, $tableBIdentifier, $expression, $joinType);
            $tableAIdentifier = $tableBIdentifier;
        }

        return true;
    }

    private function getOptionalSortFunction()
    {
        $token = reset($this->request);

        if (!self::scanFunction($token, $name, $arguments)) {
            return false;
        }

        if ($name !== 'sort') {
            return false;
        }

        if (!isset($arguments) || count($arguments) !== 1) {
            // TODO: add an explanation of the missing argument, or link to the documentation
            throw new AbstractException(
                self::ERROR_NO_SORT_ARGUMENTS,
                array('token' => $token),
                "sort functions take one argument"
            );
        }

        $state = array($this->request, $this->class, $this->table);

        // TODO: verify that the "$tailProperty" is valid before it is used
        $this->request = $arguments[0];
        $this->readExpressionAndGetTail($tailProperty);
        $this->mysql->setOrderBy($this->table, $tailProperty, true);

        list($this->request, $this->class, $this->table) = $state;
        array_shift($this->request);

        return true;
    }

    private function getOptionalSliceFunction()
    {
        $token = current($this->request);

        if (!self::scanFunction($token, $name, $arguments)) {
            return false;
        }

        if ($name !== 'slice') {
            return false;
        }

        if (count($arguments) !== 2) {
            return false;
        }

        if (!self::scanParameter($arguments[0], $nameA) || !self::scanParameter($arguments[1], $nameB)) {
            return false;
        }

        if (!$this->getSubtractiveParameters($nameA, $nameB, 'integer', 'integer', $start, $end)) {
            return false;
        }

        $this->mysql->setLimit($this->table, $start, $end);

        array_shift($this->request);
        return true;
    }

    private static function scanParameter($token, &$name)
    {
        if (!self::isParameterToken($token)) {
            return false;
        }

        $name = $token[1];
        return true;
    }

    private static function scanProperty($token, &$name)
    {
        if (!self::isPropertyToken($token)) {
            return false;
        }

        $name = $token[1];
        return true;
    }

    private static function scanFunction($input, &$name, &$arguments)
    {
        if (!self::isFunctionToken($input)) {
            return false;
        }

        $name = $input[1];
        $arguments = array_slice($input, 2);
        return true;
    }

    private static function scanObject($input, &$object)
    {
        if (!self::isObjectToken($input)) {
            return false;
        }

        if (count($input) < 2) {
            return false;
        }

        $object = $input[1];
        return true;
    }

    private static function scanPath($token, &$tokens)
    {
        if (!self::isPathToken($token)) {
            return false;
        }

        $tokens = array_slice($token, 1);
        return true;
    }

    private static function isParameterToken($token)
    {
        return is_array($token) && ($token[0] === Parser::TYPE_PARAMETER);
    }

    private static function isPropertyToken($token)
    {
        return is_array($token) && (count($token) > 0) && ($token[0] === Parser::TYPE_PROPERTY);
    }

    private static function isFunctionToken($token)
    {
        return is_array($token) && (count($token) > 0) && ($token[0] === Parser::TYPE_FUNCTION);
    }

    private static function isObjectToken($token)
    {
        return is_array($token) && (count($token) > 0) && ($token[0] === Parser::TYPE_OBJECT);
    }

    private static function isPathToken($token)
    {
        return is_array($token) && ($token[0] === Parser::TYPE_PATH);
    }
}
