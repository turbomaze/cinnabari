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
use Datto\Cinnabari\Mysql\Delete;
use Datto\Cinnabari\Mysql\Expression\Column;
use Datto\Cinnabari\Mysql\Expression\Parameter;
use Datto\Cinnabari\Php\Input;

/**
 * Class DeleteCompiler
 * @package Datto\Cinnabari
 */
class DeleteCompiler extends AbstractCompiler
{
    /** @var Delete */
    protected $mysql;

    public function compile($topLevelFunction, $translatedRequest, $types)
    {
        $optimizedRequest = self::optimize($topLevelFunction, $translatedRequest);
        $this->request = $optimizedRequest;

        $this->mysql = new Delete();
        $this->input = new Input($types);

        if (!$this->enterTable()) {
            return null;
        }

        $this->getFunctionSequence();

        $mysql = $this->mysql->getMysql();

        $formatInput = $this->input->getPhp();

        if (!isset($mysql, $formatInput)) {
            return null;
        }

        $phpOutput = '$output = null;';

        return array($mysql, $formatInput, $phpOutput);
    }

    private function enterTable()
    {
        $firstElement = array_shift($this->request);
        list(, $token) = each($firstElement);

        $this->context = $this->mysql->setTable($token['table']);

        return true;
    }

    protected function getFunctionSequence()
    {
        $this->getOptionalFilterFunction();
        $this->getOptionalSortFunction();
        $this->getOptionalSliceFunction();

        $this->request = reset($this->request);

        if (!$this->readDelete()) {
            throw CompilerException::invalidMethodSequence($this->request);
        }

        return true;
    }

    protected function readDelete()
    {
        if (!self::scanFunction($this->request, $name, $arguments)) {
            return false;
        }

        if ($name !== 'delete') {
            return false;
        }

        $this->request = reset($arguments);

        return true;
    }

    protected function getSubtractiveParameters($nameA, $nameB, &$outputA)
    {
        $idA = $this->input->useSubtractiveArgument($nameA, $nameB, self::$REQUIRED);

        if ($idA === null) {
            return false;
        }

        $outputA = new Parameter($idA);
        return true;
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
        if (!isset($arguments) || (count($arguments) !== 1)) {
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
        if (!isset($arguments) || (count($arguments) !== 2)) {
            throw CompilerException::badSliceArguments($this->request);
        }

        if (
            !$this->scanParameter($arguments[0], $nameA) ||
            !$this->scanParameter($arguments[1], $nameB)
        ) {
            return false;
        }

        if (!$this->getSubtractiveParameters($nameA, $nameB, $length)) {
            return false;
        }

        $this->mysql->setLimit($length);

        array_shift($this->request);

        return true;
    }

    protected function getProperty($propertyToken, &$output, &$type)
    {
        $table = $propertyToken['table'];
        $type = $propertyToken['type'];
        $column = $propertyToken['expression'];

        $columnExpression = Delete::getAbsoluteExpression($table, $column);
        $output = new Column($columnExpression);

        return true;
    }
}
