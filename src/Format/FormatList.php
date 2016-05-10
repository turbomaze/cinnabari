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

class FormatList extends Format
{
    /** @var int|int[] */
    private $indices;

    /** @var Format */
    private $token;

    /**
     * @param int|int[] $indices
     * @param Format $token
     */
    public function __construct($indices, $token)
    {
        $this->indices = $indices;
        $this->token = $token;
    }

    public function getAssignments($variable)
    {
        $key = self::getListKey($this->indices);
        return $this->token->getAssignments("{$variable}[{$key}]");
    }

    public function getReindexes($parent, $referenceCount, $shouldReference)
    {
        $statements = array();

        if ($shouldReference) {
            $oldParent = $parent;
            $parent = self::getNewReference($referenceCount);
            $statements[] = self::getAssignment($parent, "&{$oldParent}");
        }

        $statements[] = self::getReindexStatement($parent);

        $child = self::getNewReference($referenceCount);
        $php = trim($this->token->getReindexes($child, $referenceCount, false));

        if (0 < strlen($php)) {
            $statements[] = '';
            $statements[] = self::getForeachLoop("{$parent} as &{$child}", $php);
        }

        return implode("\n", $statements) . "\n";
    }

    private static function getListKey($indices)
    {
        if (is_integer($indices)) {
            return self::getColumn($indices);
        }

        if (is_array($indices)) {
            if (count($indices) < 2) {
                throw new Exception('Not enough columns in compound index', 1);
            }

            $values = array();

            foreach ($indices as $i) {
                $values[] = self::getColumn($i);
            }

            return 'json_encode(array(' . implode(', ', $values) . '))';
        }

        throw new Exception('Invalid index', 1);
    }

    private static function getReindexStatement($variable)
    {
        return "{$variable} = is_array({$variable}) ? array_values({$variable}) : array();";
    }
}
