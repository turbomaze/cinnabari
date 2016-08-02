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

namespace Datto\Cinnabari\Exception;

class LexerException extends AbstractException
{
    const INVALID_TYPE = 1;
    const SYNTAX_ERROR = 2;

    public static function invalidType($input)
    {
        $code = self::INVALID_TYPE;

        $data = array(
            'input' => $input
        );

        $description = self::getValueDescription($input);

        $message = "Expected a string query, " .
            "but received {$description} instead.";

        return new self($code, $data, $message);
    }

    private static function getValueDescription($value)
    {
        $type = gettype($value);

        switch ($type) {
            case 'NULL':
                return 'a null value';

            case 'boolean':
                $valueJson = json_encode($value);
                return "a boolean ({$valueJson})";

            case 'integer':
                $valueJson = json_encode($value);
                return "an integer ({$valueJson})";

            case 'double':
                $valueJson = json_encode($value);
                return "a float ({$valueJson})";

            case 'string':
                $valueJson = json_encode($value);
                return "a string ({$valueJson})";

            case 'array':
                $valueJson = json_encode($value);
                return "an array ({$valueJson})";

            case 'object':
                return 'an object';

            case 'resource':
                return 'a resource';

            default:
                return 'an unknown value';
        }
    }

    public static function syntaxError($input, $position)
    {
        $code = self::SYNTAX_ERROR;

        $data = array(
            'input' => $input,
            'position' => $position
        );

        $tail = self::getTail($input, $position);
        $tailJson = json_encode($tail);

        list($line, $character) = self::getLineCharacter($input, $position);

        $message = "Syntax error near {$tailJson} " .
            "at line {$line} character {$character}.";

        return new self($code, $data, $message);
    }

    private static function getTail($input, $position)
    {
        $tail = substr($input, $position);

        if (is_string($tail)) {
            return $tail;
        }

        return '';
    }

    private static function getLineCharacter($input, $errorPosition)
    {
        $iLine = 0;
        $iCharacter = 0;

        $lines = preg_split('~\r?\n~', $input, null, PREG_SPLIT_OFFSET_CAPTURE);

        foreach ($lines as $line) {
            list($lineText, $linePosition) = $line;

            $iCharacter = $errorPosition - $linePosition;

            if ($iCharacter <= strlen($lineText)) {
                break;
            }

            ++$iLine;
        }

        return array($iLine + 1, $iCharacter + 1);
    }
}
