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

class CompilerException extends AbstractException
{
	const UNKNOWN_REQUEST_TYPE = 1;
    const NO_INITIAL_PROPERTY = 2;
    const NO_INITIAL_PATH = 3;
    const INVALID_METHOD_SEQUENCE = 4;
    const NO_FILTER_ARGUMENTS = 5;
    const BAD_FILTER_EXPRESSION = 6;
    const NO_SORT_ARGUMENTS = 7;
    const BAD_SLICE_ARGUMENTS = 8;
    const BAD_MAP_ARGUMENT = 9;
    const BAD_SCHEMA = 10;
    const BAD_TABLE_ID = 11;
    const INVALID_SELECT = 12;
    const UNKNOWN_TYPECAST = 13;

	public static function unknownRequestType($request)
	{
		$code = self::UNKNOWN_REQUEST_TYPE;
		$data = array('request' => $request);
		$message = 'Only get and delete queries are supported at the moment.';

		return new self($code, $data, $message);
	}

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
            $message = "Schema failed to return a valid property definition " .
                "for property {$property}.";
        } elseif ($accessType === 'list') {
            $list = json_encode($arguments[0]);
            $message = "Schema failed to return a valid list definition " .
                "for list {$list}.";
        } else {
            $accessType = json_encode($arguments[0]);
            $message = "Schema failed to return a valid definition " .
                "for {$accessType}.";
        }

        return new self($code, $data, $message);
    }

    public static function invalidMethodSequence($request)
    {
        $code = self::INVALID_METHOD_SEQUENCE;
        $data = array('request' => $request);
        $message = 'API requests must consist of optional filter/sort/slice ' .
            'methods followed by a map or delete.';

        return new self($code, $data, $message);
    }

    public static function noFilterArguments($token)
    {
        $code = self::NO_FILTER_ARGUMENTS;
        $data = array('token' => $token);
        $message = "Filter functions take one argument (an expression), " .
            "but no arguments were provided.";

        return new self($code, $data, $message);
    }

    public static function badFilterExpression($context, $arguments)
    {
        $code = self::BAD_FILTER_EXPRESSION;
        $data = array(
            'context' => $context,
            'arguments' => $arguments[0]
        );
        $message = 'Malformed expression supplied to the filter function.';

        return new self($code, $data, $message);
    }

    public static function badMapArgument($request)
    {
        $code = self::BAD_MAP_ARGUMENT;
        $data = array('request' => $request);
        $message = 'Map functions take a property, path, object, ' .
            'or function as an argument.';

        return new self($code, $data, $message);
    }

    public static function noSortArguments($token)
    {
        $code = self::NO_SORT_ARGUMENTS;
        $data = array('token' => $token);
        $message = 'Sort functions take one argument.';

        return new self($code, $data, $message);
    }

    public static function badSliceArguments($token)
    {
        $code = self::BAD_SLICE_ARGUMENTS;
        $data = array('token' => $token);
        $message = 'Slice functions take two integer arguments.';

        return new self($code, $data, $message);
    }

    public static function badTableId($tableId)
    {
        $code = self::BAD_TABLE_ID;
        $data = array('tableId' => $tableId);
        $tableString = json_encode($tableId);
        $message = "Unknown table id {$tableString}.";

        return new self($code, $data, $message);
    }

    public static function invalidSelect()
    {
        $code = self::INVALID_SELECT;
        $message = 'SQL queries must reference at least one table ' .
            'and one column.';

        return new self($code, null, $message);
    }

    public static function unknownTypecast($type)
    {
        $code = self::UNKNOWN_TYPECAST;
        $data = array(
            'type' => $type,
        );
        $typeString = json_encode($type);
        $message = "Failed to typecast unknown type {$typeString}.";

        return new self($code, $data, $message);
    }
}
