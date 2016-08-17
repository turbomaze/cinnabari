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

use Datto\PhpTypeInferer\InconsistentTypeException;

class CinnabariException extends AbstractException
{
    private static $lexer = 1;
    private static $translator = 2;
    private static $compiler = 3;
    private static $arguments = 4;

    private static $multiplier = 100;
    
    public static function lexer(LexerException $exception)
    {
        $code = self::getUniversalCode(self::$lexer, $exception->getCode());
        $data = $exception->getData();
        $message = $exception->getMessage();

        return new self($code, $data, $message);
    }

    public static function translator(TranslatorException $exception)
    {
        $code = self::getUniversalCode(self::$translator, $exception->getCode());
        $data = $exception->getData();
        $message = $exception->getMessage();

        return new self($code, $data, $message);
    }

    public static function compiler(CompilerException $exception)
    {
        $code = self::getUniversalCode(self::$compiler, $exception->getCode());
        $data = $exception->getData();
        $message = $exception->getMessage();

        return new self($code, $data, $message);
    }

    public static function arguments(InconsistentTypeException $exception)
    {
        $code = self::getUniversalCode(self::$arguments, $exception->getCode());
        $data = $exception->getData();
        $message = $exception->getMessage();

        return new self($code, $data, $message);
    }

    private static function getUniversalCode($category, $exception)
    {
        return (self::$multiplier * $category) + $exception;
    }
}
