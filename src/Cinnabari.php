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

use Datto\Cinnabari\Exception\ArgumentsException;
use Datto\Cinnabari\Exception\CinnabariException;
use Datto\Cinnabari\Exception\CompilerException;
use Datto\Cinnabari\Exception\LexerException;
use Datto\Cinnabari\Exception\MysqlException;
use Datto\Cinnabari\Exception\OutputException;
use Datto\Cinnabari\Exception\SchemaException;

class Cinnabari
{
    /** @var Schema */
    private $schema;

    public function __construct(Schema $schema)
    {
        $this->schema = $schema;
    }

    // TODO: trim the query string before Cinnabari
    public function translate($query, $arguments)
    {
        $tokens = self::getTokens($query);
        $request = self::getRequest($tokens);
        return self::getResult($this->schema, $request, $arguments);
    }

    private static function getTokens($query)
    {
        try {
            $lexer = new Lexer();
            return $lexer->tokenize($query);
        } catch (LexerException $exception) {
            throw new CinnabariException(CinnabariException::LEXER, $exception);
        }
    }

    private static function getRequest($tokens)
    {
        $parser = new Parser();
        return $parser->parse($tokens);
    }

    private static function getResult(Schema $schema, $request, $arguments)
    {
        try {
            $compiler = new Compiler($schema);
            return $compiler->compile($request, $arguments);
        } catch (ArgumentsException $exception) {
            throw new CinnabariException(CinnabariException::ARGUMENTS, $exception);
        } catch (CompilerException $exception) {
            throw new CinnabariException(CinnabariException::COMPILER, $exception);
        } catch (MysqlException $exception) {
            throw new CinnabariException(CinnabariException::MYSQL, $exception);
        } catch (OutputException $exception) {
            throw new CinnabariException(CinnabariException::OUTPUT, $exception);
        } catch (SchemaException $exception) {
            throw new CinnabariException(CinnabariException::SCHEMA, $exception);
        }
    }
}
