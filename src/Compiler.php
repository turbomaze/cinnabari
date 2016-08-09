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

use Datto\Cinnabari\Exception\CompilerException;
use Datto\Cinnabari\Compiler\GetCompiler;
use Datto\Cinnabari\Compiler\DeleteCompiler;

/**
 * Class Compiler
 * @package Datto\Cinnabari
 */
class Compiler
{
    const TYPE_GET = 0;
    const TYPE_DELETE = 1;

    private $getCompiler;
    private $deleteCompiler;

    public function __construct()
    {
        $this->getCompiler = new GetCompiler();
        $this->deleteCompiler = new DeleteCompiler();
    }
    
    public function compile($translatedRequest, $arguments)
    {
        $queryType = self::getQueryType($translatedRequest);

        switch ($queryType) {
            case self::TYPE_GET:
                return $this->getCompiler->compile($translatedRequest, $arguments);

            case self::TYPE_DELETE:
                return $this->deleteCompiler->compile($translatedRequest, $arguments);
    
            default:
                throw CompilerException::unknownRequestType($translatedRequest);
        }
    }

    public static function getQueryType($translatedRequest)
    {
        $lastRequest =  end($translatedRequest);
        list($lastTokenType, $lastToken) = each($lastRequest);

        if ($lastTokenType === Translator::TYPE_FUNCTION) {
            if ($lastToken['function'] === 'delete') {
                return self::TYPE_DELETE;
            }
        }

        return self::TYPE_GET;
    }
}
