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

use Datto\Cinnabari\Compiler\DeleteCompiler;
use Datto\Cinnabari\Compiler\GetCompiler;
use Datto\Cinnabari\Compiler\SetCompiler;
use Datto\Cinnabari\Exception\CompilerException;
use Datto\Cinnabari\Parser;

/**
 * Class Compiler
 * @package Datto\Cinnabari
 */
class Compiler
{
    const TYPE_UNKNOWN = -1;
    const TYPE_GET = 0;
    const TYPE_DELETE = 1;
    const TYPE_SET = 2;

    private $getCompiler;
    private $deleteCompiler;
    private $setCompiler;
    private $translator;

    public function __construct($schema)
    {
        $this->getCompiler = new GetCompiler();
        $this->deleteCompiler = new DeleteCompiler();
        $this->setCompiler = new SetCompiler();
        $this->translator = new Translator($schema);
    }
    
    public function compile($request, $arguments)
    {
        $queryType = self::getQueryType($request);

        switch ($queryType) {
            case self::TYPE_GET:
                $translatedRequest = $this->translator->translateIgnoringObjects($request);
                return $this->getCompiler->compile($translatedRequest, $arguments);

            case self::TYPE_DELETE:
                $translatedRequest = $this->translator->translateIgnoringObjects($request);
                return $this->deleteCompiler->compile($translatedRequest, $arguments);

            case self::TYPE_SET:
                $translatedRequest = $this->translator->translateIncludingObjects($request);
                return $this->setCompiler->compile($translatedRequest, $arguments);
    
            default:
                throw CompilerException::unknownRequestType($request);
        }
    }

    public static function getQueryType($request)
    {
        list($tokenType, $functionName, ) = reset($request);

        if ($tokenType === Parser::TYPE_FUNCTION) {
            if ($functionName === 'get') {
                return self::TYPE_GET;
            } else if ($functionName === 'delete') {
                return self::TYPE_DELETE;
            } else if ($functionName === 'set') {
                return self::TYPE_SET;
            }
        }

        return self::TYPE_UNKNOWN;
    }
}
