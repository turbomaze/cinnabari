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

namespace Datto\Cinnabari;

use Datto\Cinnabari\CompilerInterface;
use Datto\Cinnabari\DeleteCompiler;
use Datto\Cinnabari\Exception\CompilerException;
use Datto\Cinnabari\GetCompiler;
use Datto\Cinnabari\SemanticAnalyzer;

/**
 * Class Compiler
 * @package Datto\Cinnabari
 */

class Compiler implements CompilerInterface
{
    private $getCompiler;
    private $deleteCompiler;

    public function __construct()
    {
        $this->getCompiler = new GetCompiler();
        $this->deleteCompiler = new DeleteCompiler();
    }
    
    public function compile($translatedRequest, $arguments)
    {
        $queryType = SemanticAnalyzer::getQueryType($translatedRequest);

        switch($queryType) {
            case SemanticAnalyzer::TYPE_GET:
                return $this->getCompiler->compile($translatedRequest, $arguments);

            case SemanticAnalyzer::TYPE_DELETE:
                return $this->deleteCompiler->compile($translatedRequest, $arguments);
    
            default:
                throw new AbstractException(
                    self::ERROR_UNSUPPORTED_QUERY_TYPE,
                    array('request' => $translatedRequest),
                    "Only get and delete queries are supported at the moment."
                );
        }

        return false;
    }
}
