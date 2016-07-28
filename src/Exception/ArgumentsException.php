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

class ArgumentsException extends AbstractException
{
    const INPUT_NOT_PROVIDED = 1;
    const WRONG_INPUT_TYPE = 2;

    public static function inputNotProvided($name, $neededType)
    {
        $code = self::INPUT_NOT_PROVIDED;
        $data = array('name' => $name, 'neededType' => $neededType);
        $nameString = json_encode($name);
        $message = "Input parameter {$nameString} not provided.";
        return new self($code, $data, $message);
    }

    public static function wrongInputType($name, $userType, $neededType)
    {
        $code = self::INPUT_NOT_PROVIDED;
        $data = array(
            'name' => $name,
            'userType' => $userType,
            'neededType' => $neededType
        );
        $nameString = json_encode(':' . $name);
        $userTypeString = json_encode($userType);
        $neededTypeString = json_encode($neededType);
        $message = "{$userTypeString} type provided as {$nameString}, {$neededTypeString} type expected.";
        return new self($code, $data, $message);
    }
}
