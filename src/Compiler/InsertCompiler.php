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
use Datto\Cinnabari\Format\Arguments;
use Datto\Cinnabari\Mysql\Expression\Column;
use Datto\Cinnabari\Mysql\Insert;

/**
 * Class InsertCompiler
 * @package Datto\Cinnabari
 */
class InsertCompiler extends AbstractValuedCompiler
{
    /** @var Insert */
    protected $mysql;
    
    public function compile($topLevelFunction, $translatedRequest, $arguments)
    {
        $this->request = $translatedRequest;

        $this->mysql = new Insert();
        $this->arguments = new Arguments($arguments);

        if (!$this->enterTable()) {
            return null;
        }

        $this->getFunctionSequence();

        $mysql = $this->mysql->getMysql();

        $formatInput = $this->arguments->getPhp();

        if (!isset($mysql, $formatInput)) {
            return null;
        }

        $phpOutput = '$output = null;';

        return array($mysql, $formatInput, $phpOutput);
    }

    protected function getFunctionSequence()
    {
        if (!isset($this->request) || (count($this->request) !== 1)) {
            throw CompilerException::badInsertArgument($this->request);
        }

        $this->request = reset($this->request);

        if (!$this->readInsert()) {
            throw CompilerException::invalidMethodSequence($this->request);
        }

        return true;
    }

    protected function readInsert()
    {
        if (!self::scanFunction($this->request, $name, $arguments)) {
            return false;
        }

        if ($name !== 'insert') {
            return false;
        }

        if (!isset($arguments) || (count($arguments) !== 1)) {
            throw CompilerException::badInsertArgument($this->request);
        }

        $this->request = reset($arguments); // should have just one argument

        if (!isset($this->request) || (count($this->request) !== 1)) {
            throw CompilerException::badInsertArgument($this->request);
        }

        $this->request = reset($this->request); // ...which should not be an array

        if (!$this->readList()) {
            throw CompilerException::badInsertArgument($this->request);
        }

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
        $columnExpression = Insert::getAbsoluteExpression($tableAliasIdentifier, $column);
        $output = new Column($columnExpression);

        return true;
    }
}
