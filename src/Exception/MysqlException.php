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

class MysqlException extends AbstractException
{
    const BAD_TABLE_ID = 1;
    const INVALID_SELECT = 2;

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
        $message = 'SQL queries must reference at least one table and one column.';
        return new self($code, null, $message);
    }
}
