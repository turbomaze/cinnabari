<?php

/**
 * Copyright (C) 2016 Datto, Inc.
 *
 * This file is part of Php Type Inferer.
 *
 * Php Type Inferer is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Lesser General Public License, version 3,
 * as published by the Free Software Foundation.
 *
 * Php Type Inferer is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Lesser General Public License for more details.
 *
 * You should have received a copy of the GNU Lesser General Public License
 * along with Php Type Inferer. If not, see <http://www.gnu.org/licenses/>.
 *
 * @author Anthony Liu <igliu@mit.edu>
 * @license http://www.gnu.org/licenses/lgpl-3.0.html LGPL-3.0
 * @copyright 2016 Datto, Inc.
 * @version 0.3.5
 */

namespace Datto\PhpTypeInferer;

class InconsistentTypeException extends \Exception
{
    private $data;

    public function __construct($data, $code = 0, \Exception $previous = null)
    {
        parent::__construct('ERROR: inconsistent type constraints.', $code, $previous);

        $this->data = $data;
    }

    public function getData()
    {
        return $this->data;
    }
}
