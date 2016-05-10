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

namespace Datto\Cinnabari\Format;

use Exception;

class FormatValue extends Format
{
    const TYPE_NULL = 0;
    const TYPE_BOOLEAN = 1;
    const TYPE_INTEGER = 2;
    const TYPE_FLOAT = 3;
    const TYPE_STRING = 4;

    /** @var int */
    private $inputIndex;

    /** @var int */
    private $type;

    public function __construct($inputIndex, $type)
    {
        $this->inputIndex = $inputIndex;
        $this->type = $type;
    }

    public function getAssignments($variable)
    {
        $column = self::getColumn($this->inputIndex);
        $value = self::cast($column, $this->type);

        return self::getAssignment($variable, $value);
    }

    public function getReindexes($variable, $referenceCount, $shouldReference)
    {
        return null;
    }

    private static function cast($value, $type)
    {
        switch ($type) {
            case self::TYPE_BOOLEAN:
                return self::convert($value, 'boolean');

            case self::TYPE_INTEGER:
                return self::convert($value, 'integer');

            case self::TYPE_FLOAT:
                return self::convert($value, 'float');

            case self::TYPE_STRING:
                return $value;

            default:
                $typeName = var_export($type, true);
                throw new Exception("Unknown type ({$typeName})", 1);
        }
    }

    private static function convert($value, $type)
    {
        return "({$value} === null ? null : ({$type}){$value})";
    }
}
