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
 * @author Anthony Liu <aliu@datto.com>
 * @license http://www.gnu.org/licenses/lgpl-3.0.html LGPL-3.0
 * @copyright 2016 Datto, Inc.
 */

namespace Datto\Cinnabari\Compiler;

use Datto\Cinnabari\Exception\CompilerException;
use Datto\Cinnabari\Mysql\Expression\Column;
use Datto\Cinnabari\Mysql\Expression\Parameter;
use Datto\Cinnabari\Mysql\Update;
use Datto\Cinnabari\Php\Input;

/**
 * Class SetCompiler
 * @package Datto\Cinnabari
 */
class SetCompiler extends AbstractValuedCompiler
{
    /** @var Update */
    protected $mysql;
    
    public function compile($topLevelFunction, $translatedRequest, $types)
    {
        $optimizedRequest = self::optimize($topLevelFunction, $translatedRequest);
        $this->request = $optimizedRequest;

        $this->mysql = new Update();
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

    protected function getFunctionSequence()
    {
        $this->getOptionalFilterFunction();
        $this->getOptionalSortFunction();
        $this->getOptionalSliceFunction();

        if (!isset($this->request) || (count($this->request) !== 1)) {
            throw CompilerException::badSetArgument($this->request);
        }

        $this->request = reset($this->request);

        if (!$this->readSet()) {
            throw CompilerException::invalidMethodSequence($this->request);
        }

        return true;
    }

    protected function getSubtractiveParameters($nameA, $nameB, &$output)
    {
        $id = $this->input->useSubtractiveArgument($nameA, $nameB, self::$REQUIRED);

        if ($id === null) {
            return false;
        }

        $output = new Parameter($id);
        return true;
    }

    protected function readSet()
    {
        if (!self::scanFunction($this->request, $name, $arguments)) {
            return false;
        }

        if ($name !== 'set') {
            return false;
        }

        if (!isset($arguments) || (count($arguments) !== 1)) {
            throw CompilerException::badSetArgument($this->request);
        }

        $this->request = reset($arguments); // should have just one argument

        if (!isset($this->request) || (count($this->request) !== 1)) {
            throw CompilerException::badSetArgument($this->request);
        }

        $this->request = reset($this->request); // ...which should not be an array

        if (!$this->readList()) {
            throw CompilerException::badSetArgument($this->request);
        }

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

        if (!$this->getSubtractiveParameters($nameA, $nameB, $length)) {
            return false;
        }

        $this->mysql->setLimit($length);

        array_shift($this->request);

        return true;
    }

    protected function getProperty($propertyToken, &$output, &$type)
    {
        $type = $propertyToken['type'];
        $column = $propertyToken['expression'];

        $tableId = $this->context;
        $tableAliasIdentifier = "`{$tableId}`";
        $columnExpression = Update::getAbsoluteExpression($tableAliasIdentifier, $column);
        $output = new Column($columnExpression);

        return true;
    }
}
