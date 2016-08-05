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

use Datto\Cinnabari\Exception\CompilerException;
use Datto\Cinnabari\Format\Arguments;
use Datto\Cinnabari\Mysql\Expression\AbstractExpression;
use Datto\Cinnabari\Mysql\Expression\Column;
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
class GetCompiler extends AbstractCompiler
{
    /** @var Select */
    protected $mysql;

    /** @var String */
    private $phpOutput;
    
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

    protected function enterTable(&$idAlias, &$hasZero)
    {
        $firstElement = array_shift($this->request);
        list($tokenType, $token) = each($firstElement);

        $this->context = $this->mysql->setTable($token['table']);
        $idAlias = $this->mysql->addValue($this->context, $token['id']);
        $hasZero = $token['hasZero'];

        return true;
    }

    protected function getFunctionSequence()
    {
        $this->getOptionalFilterFunction();
        $this->getOptionalSortFunction();
        $this->getOptionalSliceFunction();

        $this->request = reset($this->request);

        if (!$this->readMap()) {
            throw CompilerException::invalidMethodSequence($this->request);
        }

        return true;
    }

    protected function getSubtractiveParameters($nameA, $nameB, $typeA, $typeB, &$outputA, &$outputB)
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
        list($tokenType, $token) = each($firstElement);

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

    protected function getMap($arguments)
    {
        $this->request = reset($arguments);
        return $this->readExpression();
    }

    protected function readMap()
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
            throw CompilerException::badMapArgument($this->request);
        }

        return true;
    }

    protected function readFunction()
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

        $this->mysql->setLimit($this->context, $start, $end);

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
