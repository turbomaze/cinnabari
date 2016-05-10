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
use Datto\Cinnabari\Format\Format;
use Datto\Cinnabari\Format\FormatList;
use Datto\Cinnabari\Format\FormatObject;
use Datto\Cinnabari\Format\FormatValue;
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
use Exception;

class Compiler
{
    /** @var Select */
    private $select;

    /** @var Arguments */
    private $arguments;

    /** @var Format */
    private $format;

    /** @var Schema */
    private $schema;

    public function __construct(Schema $schema)
    {
        $this->schema = $schema;
    }

    public function compile($request, $arguments)
    {
        $this->arguments = new Arguments($arguments);

        if (!$this->evaluate($request)) {
            return null;
        }

        $mysql = $this->select->getMysql();
        $formatInput = $this->arguments->getPhp();
        $formatOutput = $this->format->getPhp();

        return array($mysql, $formatInput, $formatOutput);
    }

    private function evaluate($request)
    {
        return self::scanPath($request, $tokens)
            && $this->readRelease($tokens)
            && $this->readArray($tokens, $class, $table, $tableId)
            && $this->readFilter($tokens, $class, $tableId)
            && $this->readMap($tokens, $class, $table, $tableId);
    }

    private function readRelease(&$tokens)
    {
        $token = current($tokens);

        if (!self::scanProperty($token, $release)) {
            return false;
        }

        array_shift($tokens);

        return $this->schema->setRelease($release);
    }

    private function readArray(&$tokens, &$class, &$table, &$tableId)
    {
        $token = current($tokens);

        if (!self::scanProperty($token, $property)) {
            return false;
        }

        array_shift($tokens);

        list($class, $table) = $this->schema->getDatabaseDefinition($property);

        $this->select = new Select();
        $tableId = $this->select->setTable($table);

        return true;
    }

    private function readFilter(&$tokens, $class, $tableId)
    {
        $token = current($tokens);

        if (
            self::scanFunction($token, $name, $arguments) &&
            ($name === 'filter') &&
            $this->getExpression($class, $tableId, $arguments[0], $where)
        ) {
            $this->select->setWhere($where);
            array_shift($tokens);
        }

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
        return $this->getProperty($class, $tableId, $name, FormatValue::TYPE_BOOLEAN, $output);
    }

    private function getProperty($class, $tableId, $name, $expectedType, &$output)
    {
        list($actualType, $column) = $this->schema->getDefinition($class, $name);

        if ($actualType !== $expectedType) {
            return false;
        }

        // TODO
        $table = "`{$tableId}`";
        $columnExpression = Select::getAbsoluteExpression($table, $column);

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
        return $this->getProperty($class, $tableId, $name, FormatValue::TYPE_INTEGER, $output)
            || $this->getProperty($class, $tableId, $name, FormatValue::TYPE_FLOAT, $output);
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
        return $this->getProperty($class, $tableId, $name, FormatValue::TYPE_STRING, $output);
    }

    private function readMap(&$tokens, $class, $table, $tableId)
    {
        $token = current($tokens);

        if (
            !self::scanFunction($token, $name, $arguments) ||
            ($name !== 'map') ||
            !(
                $this->readProperty($class, $tableId, $arguments[0], $format) ||
                $this->readObject($class, $tableId, $arguments[0], $format)
            )
        ) {
            return false;
        }

        array_shift($tokens);

        $columnIds = array();

        $unique = $this->schema->getUnique($table);

        foreach ($unique as $column) {
            $columnIds[] = $this->select->addColumn($tableId, $column);
        }

        $countColumnIds = count($columnIds);

        if ($countColumnIds < 0) {
            throw new Exception('Not enough unique columns', 1);
        }

        if ($countColumnIds === 1) {
            $index = current($columnIds);
        } else {
            $index = $columnIds;
        }

        $this->format = new FormatList($index, $format);
        return true;
    }

    private function readProperty($class, $tableId, $token, &$format)
    {
        if (!self::scanProperty($token, $property)) {
            return false;
        }

        list($type, $column) = $this->schema->getDefinition($class, $property);

        $columnId = $this->select->addColumn($tableId, $column);
        $format = new FormatValue($columnId, $type);
        return true;
    }

    private function readObject($class, $tableId, $token, &$format)
    {
        if (!self::scanObject($token, $object)) {
            return false;
        }

        $properties = array();

        foreach ($object as $label => $propertyToken) {
            if (!self::scanProperty($propertyToken, $property)) {
                return false;
            }

            list($type, $column) = $this->schema->getDefinition($class, $property);

            $columnId = $this->select->addColumn($tableId, $column);
            $properties[$label] = new FormatValue($columnId, $type);
        }

        $format = new FormatObject($properties);
        return true;
    }

    private static function scanPath($input, &$tokens)
    {
        if (!self::isPathToken($input)) {
            return false;
        }

        $tokens = array_slice($input, 1);
        return true;
    }

    private static function scanProperty($input, &$name)
    {
        if (!self::isPropertyToken($input)) {
            return false;
        }

        $name = $input[1];
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
}
