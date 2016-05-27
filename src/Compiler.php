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
            return array(null, null, null);
        }

        $mysql = $this->mysql->getMysql();
        $formatInput = $this->arguments->getPhp();

        return array($mysql, $formatInput, $this->phpOutput);
    }

    protected function getArrayProperty()
    {
        if (!self::scanPath($this->request, $this->request)) {
            return false;
        }

        $token = array_shift($this->request);

        if (!self::scanProperty($token, $array)) {
            return false;
        }

        list($class, $path, $list) = $this->schema->getPropertyDefinition('Database', $array);

        if (!isset($class, $path, $list)) {
            return false;
        }

        list($table, $id, $hasZero) = $this->schema->getListDefinition($list);

        $this->class = $class;
        $this->table = $this->mysql->setTable($table);

        $idAlias = $this->mysql->addColumn($this->table, $id);
        $this->addJoins($this->table, $table, $path);

        $this->getOptionalFilterFunction();

        if (!$this->getMapFunction()) {
            return false;
        }

        $this->phpOutput = Output::getList($idAlias, $hasZero, true, $this->phpOutput);
        return true;
    }

    private function addJoins(&$contextId, $tableAIdentifier, &$path)
    {
        foreach ($path as $key => $value) {
            if (substr($value, 0, 1) !== '*') {
                return;
            }

            $joinName = substr($value, 1);
            $definition = $this->schema->getJoin($tableAIdentifier, $joinName);

            list($tableBIdentifier, $expression, $allowsZeroMatches, $allowsMultipleMatches) = $definition;

            if (!$allowsZeroMatches && !$allowsMultipleMatches) {
                $joinType = Select::JOIN_INNER;
            } else {
                $joinType = Select::JOIN_LEFT;
            }

            $contextId = $this->mysql->addJoin($contextId, $tableBIdentifier, $expression, $joinType);
            unset($path[$key]);
        }
    }

    protected function getOptionalFilterFunction()
    {
        $token = current($this->request);

        if (!self::scanFunction($token, $name, $arguments)) {
            return false;
        }

        if ($name !== 'filter') {
            return false;
        }

        if (!$this->readExpression($this->class, $this->table, $arguments[0], $where)) {
            return false;
        }

        $this->mysql->setWhere($where);

        array_shift($this->request);
        return true;
    }

    protected function getMapFunction()
    {
        $token = current($this->request);

        if (!self::scanFunction($token, $name, $arguments)) {
            return false;
        }

        if ($name !== 'map') {
            return false;
        }

        if (
            !$this->readProperty($this->class, $this->table, $arguments[0], $this->phpOutput) &&
            !$this->readObject($this->class, $this->table, $arguments[0], $this->phpOutput)
        ) {
            return false;
        }

        array_shift($this->request);
        return true;
    }

    private function readExpression($class, $tableId, $token, &$expression)
    {
        return $this->readBooleanExpression($class, $tableId, $token, $expression)
            || $this->readNumericExpression($class, $tableId, $token, $expression)
            || $this->readStringExpression($class, $tableId, $token, $expression);
    }

    private function readBooleanExpression($class, $tableId, $token, &$output)
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

    private function getProperty($class, $tableId, $name, $neededType, &$output)
    {
        $tableIdentifier = $this->mysql->getTable($tableId);

        list($actualType, $path, $value) = $this->schema->getPropertyDefinition($class, $name);

        if ($neededType !== $actualType) {
            return false;
        }

        list($column, ) = $this->schema->getValueDefinition($tableIdentifier, $value);

        $this->addJoins($tableId, $tableIdentifier, $path);

        $tableAliasIdentifier = "`{$tableId}`";
        $columnExpression = Select::getAbsoluteExpression($tableAliasIdentifier, $column);
        $output = new Column($columnExpression);

        return true;
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

        if (!$this->readBooleanExpression($class, $tableId, $argument, $childExpression)) {
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
                $this->readBooleanExpression($class, $tableId, $argumentA, $expressionA) &&
                $this->readBooleanExpression($class, $tableId, $argumentB, $expressionB)
            ) || (
                $this->readNumericExpression($class, $tableId, $argumentA, $expressionA) &&
                $this->readNumericExpression($class, $tableId, $argumentB, $expressionB)
            ) || (
                $this->readStringExpression($class, $tableId, $argumentA, $expressionA) &&
                $this->readStringExpression($class, $tableId, $argumentB, $expressionB)
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
            !$this->readBooleanExpression($class, $tableId, $argumentA, $outputA) ||
            !$this->readBooleanExpression($class, $tableId, $argumentB, $outputB)
        ) {
            return false;
        }

        $expression = new OperatorAnd($outputA, $outputB);
        return true;
    }

    private function getOrFunction($class, $tableId, $argumentA, $argumentB, &$expression)
    {
        if (
            !$this->readBooleanExpression($class, $tableId, $argumentA, $outputA) ||
            !$this->readBooleanExpression($class, $tableId, $argumentB, $outputB)
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
                $this->readNumericExpression($class, $tableId, $argumentA, $expressionA) &&
                $this->readNumericExpression($class, $tableId, $argumentB, $expressionB)
            ) || (
                $this->readStringExpression($class, $tableId, $argumentA, $expressionA) &&
                $this->readStringExpression($class, $tableId, $argumentB, $expressionB)
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
                $this->readNumericExpression($class, $tableId, $argumentA, $expressionA) &&
                $this->readNumericExpression($class, $tableId, $argumentB, $expressionB)
            ) || (
                $this->readStringExpression($class, $tableId, $argumentA, $expressionA) &&
                $this->readStringExpression($class, $tableId, $argumentB, $expressionB)
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
                $this->readNumericExpression($class, $tableId, $argumentA, $expressionA) &&
                $this->readNumericExpression($class, $tableId, $argumentB, $expressionB)
            ) || (
                $this->readStringExpression($class, $tableId, $argumentA, $expressionA) &&
                $this->readStringExpression($class, $tableId, $argumentB, $expressionB)
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
                $this->readNumericExpression($class, $tableId, $argumentA, $expressionA) &&
                $this->readNumericExpression($class, $tableId, $argumentB, $expressionB)
            ) || (
                $this->readStringExpression($class, $tableId, $argumentA, $expressionA) &&
                $this->readStringExpression($class, $tableId, $argumentB, $expressionB)
            )
        ) {
            $expression = new OperatorGreaterEqual($expressionA, $expressionB);
            return true;
        }

        return false;
    }


    private function readNumericExpression($class, $tableId, $token, &$output)
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
            !$this->readNumericExpression($class, $tableId, $argumentA, $expressionA) ||
            !$this->readNumericExpression($class, $tableId, $argumentB, $expressionB)
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

    private function readStringExpression($class, $tableId, $token, &$output)
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

    private function readProperty($class, $tableId, $token, &$phpValue)
    {
        if (!self::scanProperty($token, $property)) {
            return false;
        }

        $tableIdentifier = $this->mysql->getTable($tableId);

        list($type, $path, $value) = $this->schema->getPropertyDefinition($class, $property);
        list($column, $isColumnNullable) = $this->schema->getValueDefinition($tableIdentifier, $value);

        $this->addJoins($tableId, $tableIdentifier, $path);
        $columnId = $this->mysql->addColumn($tableId, $column);

        $phpValue = Output::getValue($columnId, $isColumnNullable, $type);
        return true;
    }

    private function readObject($class, $tableId, $token, &$format)
    {
        if (!self::scanObject($token, $object)) {
            return false;
        }

        $properties = array();

        foreach ($object as $label => $propertyToken) {
            if (
                !$this->readObject($class, $tableId, $propertyToken, $phpValue) &&
                !$this->readProperty($class, $tableId, $propertyToken, $phpValue) &&
                !$this->readPath($class, $tableId, $propertyToken, $phpValue)
            ) {
                return false;
            }

            $properties[$label] = $phpValue;
        }

        $format = Output::getObject($properties);
        return true;
    }

    private function readPath ($class, $tableId, $pathToken, &$phpValue)
    {
        if (!self::scanPath($pathToken, $tokens)) {
            return false;
        }

        $finalToken = array_pop($tokens);

        foreach ($tokens as $token) {
            if (!self::scanProperty($token, $propertyName)) {
                return false;
            }

            list($class, $path) = $this->schema->getPropertyDefinition($class, $propertyName);

            $tableIdentifier = $this->mysql->getTable($tableId);
            $this->addJoins($tableId, $tableIdentifier, $path);
        }

        var_dump($finalToken);

        return $this->readProperty($class, $tableId, $finalToken, $phpValue)
            || $this->readObject($class, $tableId, $finalToken, $phpValue);
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

    protected function getState()
    {
        return array(
            self::copy($this->request),
            self::copy($this->arguments),
            self::copy($this->mysql),
            self::copy($this->phpOutput)
        );
    }

    private static function copy($value)
    {
        if (is_object($value)) {
            return clone $value;
        }

        return $value;
    }

    protected function setState($state)
    {
        list($this->request, $this->arguments, $this->mysql, $this->phpOutput) = $state;
    }
}
