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

use Datto\Cinnabari\Mysql\Select;
use Datto\Cinnabari\Format\Arguments;
use Datto\Cinnabari\Php\Output;
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
use Datto\Cinnabari\Mysql\Expression\OperatorTimes;
use Datto\Cinnabari\Mysql\Expression\Parameter;

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
    const ERROR_SYNTAX = 1;

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
            return false;
        }

        $token = array_shift($this->request);

        if (!self::scanProperty($token, $array)) {
            return false;
        }

        list($class, $path) = $this->schema->getPropertyDefinition('Database', $array);
        $list = array_pop($path);

        if (!isset($class, $path, $list)) {
            return false;
        }

        list($table, $id, $hasZero) = $this->schema->getListDefinition($list);

        $this->class = $class;
        $this->table = $this->mysql->setTable($table);

        $idAlias = $this->mysql->addValue($this->table, $id);
        $this->connections($this->table, $table, $path);

        $this->getOptionalFilterFunction();

        $this->request = reset($this->request);

        if (!$this->readMap()) {
            return false;
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

        if (!$this->getExpression($this->class, $this->table, $arguments[0], $where)) {
            return false;
        }

        $this->mysql->setWhere($where);

        array_shift($this->request);
        return true;
    }

    private function getExpression($class, $tableId, $token, &$expression)
    {
        return $this->getBooleanExpression($class, $tableId, $token, $expression)
        || $this->getNumericExpression($class, $tableId, $token, $expression)
        || $this->getStringExpression($class, $tableId, $token, $expression);
    }

    private function getBooleanExpression($class, $tableId, $token, &$output)
    {
        list($type, $name) = $token;

        switch ($type) {
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
                $this->getNumericExpression($class, $tableId, $argumentA, $expressionA) &&
                $this->getNumericExpression($class, $tableId, $argumentB, $expressionB)
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
                $this->getNumericExpression($class, $tableId, $argumentA, $expressionA) &&
                $this->getNumericExpression($class, $tableId, $argumentB, $expressionB)
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
                $this->getNumericExpression($class, $tableId, $argumentA, $expressionA) &&
                $this->getNumericExpression($class, $tableId, $argumentB, $expressionB)
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
                $this->getNumericExpression($class, $tableId, $argumentA, $expressionA) &&
                $this->getNumericExpression($class, $tableId, $argumentB, $expressionB)
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
                $this->getNumericExpression($class, $tableId, $argumentA, $expressionA) &&
                $this->getNumericExpression($class, $tableId, $argumentB, $expressionB)
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


    private function getNumericExpression($class, $tableId, $token, &$output)
    {
        $type = $token[0];

        switch ($type) {
            case Parser::TYPE_PARAMETER:
                return $this->getNumericParameter($token[1], $output);

            case Parser::TYPE_PROPERTY:
                return $this->getNumericProperty($class, $tableId, $token[1], $output);

            case Parser::TYPE_FUNCTION:
                return $this->getNumericBinaryFunction($class, $tableId, $token[1], $token[2], $token[3], $output);

            default:
                return false;
        }
    }

    private function getNumericParameter($name, &$output)
    {
        return $this->getParameter($name, 'integer', $output)
        || $this->getParameter($name, 'double', $output);
    }

    private function getNumericProperty($class, $tableId, $name, &$output)
    {
        return $this->getProperty($class, $tableId, $name, Output::TYPE_INTEGER, $output)
        || $this->getProperty($class, $tableId, $name, Output::TYPE_FLOAT, $output);
    }

    private function getNumericBinaryFunction($class, $tableId, $name, $argumentA, $argumentB, &$expression)
    {
        if (
            !$this->getNumericExpression($class, $tableId, $argumentA, $expressionA) ||
            !$this->getNumericExpression($class, $tableId, $argumentB, $expressionB)
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

    private function getStringExpression($class, $tableId, $token, &$output)
    {
        $type = $token[0];

        switch ($type) {
            case Parser::TYPE_PARAMETER:
                return $this->getStringParameter($token[1], $output);

            case Parser::TYPE_PROPERTY:
                return $this->getStringProperty($class, $tableId, $token[1], $output);

            default:
                return false;
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
        $value = array_pop($path);

        if ($neededType !== $actualType) {
            return false;
        }

        list($column, ) = $this->schema->getValueDefinition($tableIdentifier, $value);

        $this->connections($tableId, $tableIdentifier, $path);

        $tableAliasIdentifier = "`{$tableId}`";
        $columnExpression = Select::getAbsoluteExpression($tableAliasIdentifier, $column);
        $output = new Column($columnExpression);

        return true;
    }

    private function readExpression()
    {
        $token = $this->request;

        $type = $token[0];

        switch ($type) {
            case Parser::TYPE_PATH:
                return $this->readPath();

            case Parser::TYPE_PROPERTY:
                return $this->readProperty();

            case Parser::TYPE_OBJECT:
                return $this->readObject();

            case Parser::TYPE_FUNCTION:
                return $this->readMap();

            default:
                return false;
        }
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

    private function readProperty()
    {
        if (!self::scanProperty($this->request, $property)) {
            return false;
        }

        list($type, $path) = $this->schema->getPropertyDefinition($this->class, $property);

        $value = array_pop($path);

        $tableIdentifier = $this->mysql->getTable($this->table);
        $this->connections($this->table, $tableIdentifier, $path);

        list($column, $isColumnNullable) = $this->schema->getValueDefinition($tableIdentifier, $value);
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

    private function readMap()
    {
        if (!self::scanFunction($this->request, $name, $arguments)) {
            return false;
        }

        if ($name !== 'map') {
            return false;
        }

        $this->request = reset($arguments);

        return $this->readExpression();
    }

    private function connections(&$contextId, $tableAIdentifier, $connections)
    {
        foreach ($connections as $key => $connection) {
            $definition = $this->schema->getConnectionDefinition($tableAIdentifier, $connection);

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
        }
    }

    private static function scanPath($token, &$tokens)
    {
        if (!self::isPathToken($token)) {
            return false;
        }

        $tokens = array_slice($token, 1);
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
        if (($input === null) || ($input[0] !== Parser::TYPE_OBJECT)) {
            return false;
        }

        $object = $input[1];
        return true;
    }

    private static function isPathToken($token)
    {
        return is_array($token) && ($token[0] === Parser::TYPE_PATH);
    }

    private static function isPropertyToken($token)
    {
        return is_array($token) && ($token[0] === Parser::TYPE_PROPERTY);
    }

    private static function isFunctionToken($token)
    {
        return is_array($token) && ($token[0] === Parser::TYPE_FUNCTION);
    }

    private function getState()
    {
        return array(
            'mysql' => $this->mysql,
            'arguments' => $this->arguments,
            'phpOutput' => $this->phpOutput,
            'class' => $this->class,
            'table' => $this->table
        );
    }

    private function setState($state)
    {
        $this->mysql = $state['mysql'];
        $this->arguments = $state['arguments'];
        $this->phpOutput = $state['phpOutput'];
        $this->class = $state['class'];
        $this->table = $state['table'];
    }
}
