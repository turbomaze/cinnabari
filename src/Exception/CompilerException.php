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

class CompilerException extends AbstractException
{
    const NO_INITIAL_PROPERTY = 1;
    const NO_INITIAL_PATH = 2;
    const INVALID_METHOD_SEQUENCE = 3;
    const NO_FILTER_ARGUMENTS = 4;
    const BAD_FILTER_EXPRESSION = 5;
    const NO_SORT_ARGUMENTS = 6;
    const BAD_MAP_ARGUMENT = 7;
    const BAD_SCHEMA = 8;

    public static function noInitialProperty($token)
    {
        $code = self::NO_INITIAL_PROPERTY;
        $data = array('token' => $token);
        $message = 'API requests must begin with a property.';
        return new self($code, $data, $message);
    }

    public static function noInitialPath($request)
    {
        $code = self::NO_INITIAL_PATH;
        $data = array('request' => $request);
        $message = 'API requests must begin with a path.';
        return new self($code, $data, $message);
    }

    public static function badSchema($accessType, $arguments)
    {
        $code = self::BAD_SCHEMA;
        $data = array('accessType' => $accessType, 'arguments' => $arguments);
        $message = null;
        if ($accessType === 'property') {
            $property = json_encode($arguments[0]);
            $message = "Schema failed to return a valid property definition for property {$property}.";
        } elseif ($accessType === 'list') {
            $list = json_encode($arguments[0]);
            $message = "Schema failed to return a valid list definition for list {$list}.";
        } else {
            $accessType = json_encode($arguments[0]);
            $message = "Schema failed to return a valid definition for {$accessType}.";
        }
        return new self($code, $data, $message);
    }

    public static function invalidMethodSequence($request)
    {
        $code = self::INVALID_METHOD_SEQUENCE;
        $data = array('request' => $request);
        $message = 'API requests must consist of optional filter/sort/slice methods followed by a map.';
        return new self($code, $data, $message);
    }

    public static function noFilterArguments($token)
    {
        $code = self::NO_FILTER_ARGUMENTS;
        $data = array('token' => $token);
        $message = 'Filter functions take one expression argument, none provided.';
        return new self($code, $data, $message);
    }

    public static function badFilterExpression($class, $table, $arguments)
    {
        $code = self::BAD_FILTER_EXPRESSION;
        $data = array(
            'class' => $class,
            'table' => $table,
            'arguments' => $arguments[0]
        );
        $message = 'Malformed expression supplied to the filter function.';
        return new self($code, $data, $message);
    }

    public static function badMapArgument($request)
    {
        $code = self::BAD_MAP_ARGUMENT;
        $data = array('request' => $request);
        $message = 'Map functions take a property, path, object, or function as an argument.';
        return new self($code, $data, $message);
    }

    public static function noSortArguments($token)
    {
        $code = self::BAD_MAP_ARGUMENT;
        $data = array('token' => $token);
        $message = 'Sort functions take one argument.';
        return new self($code, $data, $message);
    }
}
