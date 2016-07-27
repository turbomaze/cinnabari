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
 * @license http://www.gnu.org/licenses/lgpl-3.0.html LGPL-3.0
 * @copyright 2016 Datto, Inc.
 */

namespace Datto\Cinnabari;

use Datto\Cinnabari\Exception\AbstractException;

class Cinnabari
{
    const ERROR_SYNTAX = 1;

    /** @var Schema */
    private $schema;

    public function __construct(Schema $schema)
    {
        $this->schema = $schema;
    }

    // TODO: trim the query string before Cinnabari
    public function translate($query, $arguments)
    {
        try {
            $tokens = self::getTokens($query);
            $request = self::getRequest($tokens);
            return self::getResult($this->schema, $request, $arguments);
        } catch (LexerException $exception) {
            return false;
        } catch (AbstractException $exception) {
            return false;
        }
    }

    private static function getTokens($query)
    {
        $lexer = new Lexer();
        return $lexer->tokenize($query);
    }

    private static function getRequest($tokens)
    {
        $parser = new Parser();
        return $parser->parse($tokens);
    }

    private static function getResult(Schema $schema, $request, $arguments)
    {
        $compiler = new Compiler($schema);
        return $compiler->compile($request, $arguments);
    }
}
