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
 * @author Anthony Liu <igliu@mit.edu>
 * @license http://www.gnu.org/licenses/lgpl-3.0.html LGPL-3.0
 * @copyright 2016 Datto, Inc.
 */

namespace Datto\Cinnabari\Exception;

class CinnabariException extends AbstractException
{
    const ARGUMENTS = 1;
    const COMPILER = 2;
    const GRAMMAR = 3;
    const LEXER = 4;
    const MYSQL = 5;
    const OUTPUT = 6;
    const SCHEMA = 7;

    private $originalCode;

    public function __construct($code, $exception)
    {
        $this->originalCode = $exception->getCode();
            
        parent::__construct($code, $exception->getData(), $exception->getMessage());
    }

    public function getOriginalCode()
    {
        return $this->originalCode;
    }
}
