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

class FormatObject extends Format
{
    /** @var array */
    private $properties;

    public function __construct($properties)
    {
        $this->properties = $properties;
    }

    public function getAssignments($variable)
    {
        $countProperties = count($this->properties);

        if ($countProperties === 0) {
            throw new Exception('Empty object', 1);
        }

        $assignments = array();

        if ((1 < $countProperties) && ($variable !== '$output')) {
            $oldVariable = $variable;
            $variable = self::getNewReferenceName();
            $assignments[] = self::getAssignment($variable, "&{$oldVariable}");
        }

        /** @var Format $token */
        foreach ($this->properties as $name => $token) {
            $key = var_export($name, true);
            $assignments[] = $token->getAssignments("{$variable}[{$key}]");
        }

        return implode("\n", $assignments);
    }

    public function getReindexes($variable, $referenceCount, $shouldReference)
    {
        $reindexes = array();

        /** @var Format $token */
        foreach ($this->properties as $name => $token) {
            $key = var_export($name, true);
            $childVariable = "{$variable}[{$key}]";

            $php = $token->getReindexes($childVariable, $referenceCount, true);

            if (0 < strlen($php)) {
                $reindexes[] = $php;
            }
        }

        return implode("\n", $reindexes);
    }
}
