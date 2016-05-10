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

abstract class Format
{
    /** @var int */
    protected static $referenceCount;

    public function getPhp()
    {
        self::$referenceCount = 0;

        $body = trim($this->getAssignments('$output'));

        $output = self::getForeachLoop('$input as $row', $body);

        self::$referenceCount = 0;

        $output .= "\n\n" . $this->getReindexes('$output', 0, false);

        return trim($output);
    }

    /**
     * @param string $variable
     * @return string
     */
    abstract public function getAssignments($variable);

    /**
     * @param string $variable
     * @param int $referenceCount
     * @param bool $shouldReference
     * @return string
     */
    abstract public function getReindexes($variable, $referenceCount, $shouldReference);

    /**
     * @param int $i
     * @return string
     */
    protected static function getColumn($i)
    {
        return "\$row['{$i}']";
    }

    /**
     * @param string $variable
     * @param string $value
     * @return string
     */
    protected static function getAssignment($variable, $value)
    {
        return "{$variable} = {$value};";
    }

    /**
     * @return string
     */
    protected static function getNewReferenceName()
    {
        return '$x' . (self::$referenceCount++);
    }

    /**
     * @param int $referenceCount
     * @return string
     */
    protected static function getNewReference(&$referenceCount)
    {
        return '$x' . ($referenceCount++);
    }

    /**
     * @param string $condition
     * @param string $body
     * @return string
     */
    protected static function getForeachLoop($condition, $body)
    {
        $body = self::indent($body);
        return "foreach ({$condition}) {\n{$body}\n}";
    }

    /**
     * @param string $text
     * @return string
     */
    private static function indent($text)
    {
        return preg_replace('~(?:^|\n)(?!\n)~s', "\$0\t", $text);
    }
}
