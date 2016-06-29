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
        $tokens = self::getTokens($query);
        $request = self::getRequest($tokens);

        return self::getResult($this->schema, $request, $arguments);
    }

    private static function getTokens($query)
    {
        try {
            $lexer = new Lexer();
            return $lexer->tokenize($query);
        } catch (Exception $exception) {
            $position = $exception->getData();
            self::errorSyntax($query, $position);
            return null;
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
            $symbolTable = new SymbolTable($schema);
            $typeInferer = new TypeInferer();
            $compiler = new Compiler();
            list($symbols, $preamble, $annotatedTree) = $symbolTable->getSymbols($request);
            list($symbols) = $typeInferer->infer($symbols, $annotatedTree);
            return $compiler->compile($symbols, $preamble, $annotatedTree, $arguments);
        } catch (Exception $exception) {

            // TODO:
            echo $exception->getMessage(), "\n";

            return null;
        }
    }

    private static function errorSyntax($query, $position)
    {
        if ($position === null) {
            $queryType = self::getType($query);
            $queryName = var_export($query, true);
            $indefiniteArticle = self::getIndefiniteArticle($queryType);

            $message = "Syntax error: expected string input, but received {$indefiniteArticle} {$queryType} value instead ({$queryName})";
        } elseif (strlen($query) === 0) {
            $message = "Syntax error: expected a query string, but received an empty string instead";
        } else {
            $queryCursorName = self::underline($query, $position);

            $message = "Syntax error at position {$position}: {$queryCursorName}";
        }

        throw new Exception(self::ERROR_SYNTAX, $position, $message);
    }

    private static function getType($value)
    {
        $type = gettype($value);

        if ($type === 'NULL') {
            return 'null';
        }

        if ($type === 'double') {
            return 'float';
        }

        return $type;
    }

    private static function getIndefiniteArticle($word)
    {
        $firstLetter = substr($word, 0, 1);

        if (
            ($firstLetter === 'a') ||
            ($firstLetter === 'e') ||
            ($firstLetter === 'i') ||
            ($firstLetter === 'o') ||
            ($firstLetter === 'u')
        ) {
            return 'an';
        }

        return 'a';
    }

    private static function underline($text, $beg = null, $end = null)
    {
        $underlineCharacter = pack("CC", 0xcc, 0xb2);

        $textEnd = strlen($text) - 1;

        $beg = is_null($beg) ? 0 : max($beg, 0);
        $end = is_null($end) ? $textEnd : min($end, $textEnd);

        $input = json_encode($text);
        $output = '';

        ++$beg;
        ++$end;

        for ($i = 0, $length = strlen($input); $i < $length; ++$i) {
            if (($beg <= $i) && ($i <= $end)) {
                $output .= $underlineCharacter;
            }

            $output .= $input[$i];
        }

        return $output;
    }
}
