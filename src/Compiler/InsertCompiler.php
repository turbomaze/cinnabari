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

use Datto\Cinnabari\Exception\ArgumentsException;
use Datto\Cinnabari\Exception\CompilerException;
use Datto\Cinnabari\Format\Arguments;
use Datto\Cinnabari\Mysql\Expression\AbstractExpression;
use Datto\Cinnabari\Mysql\Expression\Column;
use Datto\Cinnabari\Mysql\Expression\Parameter;
use Datto\Cinnabari\Mysql\Insert;
use Datto\Cinnabari\Translator;

/**
 * Class InsertCompiler
 * @package Datto\Cinnabari
 */
class InsertCompiler extends AbstractCompiler
{
    /** @var Insert */
    protected $mysql;
    
    public function compile($translatedRequest, $arguments)
    {
        $this->request = $translatedRequest;

        $this->mysql = new Insert();
        $this->arguments = new Arguments($arguments);

        if (!$this->enterTable($hasZero)) {
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

    protected function enterTable(&$hasZero)
    {
        $firstElement = array_shift($this->request);
        list(, $token) = each($firstElement);

        $this->context = $this->mysql->setTable($token['table']);
        $hasZero = $token['hasZero'];

        return true;
    }

    protected function getFunctionSequence()
    {
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

        $this->request = reset($arguments); // should have just one argument
        $this->request = reset($this->request); // ...which should not be an array

        if (!$this->readList()) {
            throw CompilerException::badSetArgument($this->request);
        }

        return true;
    }

    protected function readList()
    {
        if (!$this->scanList($this->request, $list)) {
            return false;
        }

        $initialContext = $this->context;
        foreach ($list as $index => $pair) {
            $this->context = $initialContext;
            $property = $pair['property'];
            $this->getColumnFromPropertyPath($property, $column);

            $this->context = $initialContext;
            $value = $pair['value'];
            $this->getExpression($value, $expression, $type);

            $this->mysql->addPropertyValuePair($this->context, $column, $expression);
        }

        return true;
    }

    // TODO: type checking
    protected function getColumnFromPropertyPath($arrayToken, &$output)
    {
        $arrayToken = $this->followJoins($arrayToken);
        $propertyToken = reset($arrayToken);
        list(, $property) = each($propertyToken);
        $output = new Column($property['expression']);
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

    protected function getParameter($name, $type, &$output)
    {
        $id = null;
        try {
            $id = $this->arguments->useArgument($name, $type);
        } catch (ArgumentsException $exception) {
            // suppress type exceptions for now
            if ($exception->getCode() !== ArgumentsException::WRONG_INPUT_TYPE) {
                throw $exception;
            }
        }

        if ($id === null) {
            return false;
        }

        $output = new Parameter($id);
        return true;
    }

    protected function scanList($input, &$object)
    {
        list($tokenType, $token) = each($input);

        if ($tokenType !== Translator::TYPE_LIST) {
            return false;
        }

        $object = $token;
        return true;
    }
}

