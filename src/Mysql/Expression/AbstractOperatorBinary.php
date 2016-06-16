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

namespace Datto\Cinnabari\Mysql\Expression;

abstract class AbstractOperatorBinary extends AbstractExpression
{
    /** @var string */
    private $operator;

    /** @var AbstractExpression */
    private $left;

    /** @var AbstractExpression */
    private $right;

    public function __construct($operator, &$left, &$right)
    {
        $this->operator = $operator;

        // copy over the nullability of its sibling
        if (is_a($left, 'Datto\Cinnabari\Mysql\Expression\Parameter')) {
            $left->nullable = $right->nullable;
        } else if (is_a($right, 'Datto\Cinnabari\Mysql\Expression\Parameter')) {
            $right->nullable = $left->nullable;
        }

        // make the assignment
        $this->left = $left;
        $this->right = $right;
        $this->nullable = $left->nullable && $right->nullable;
    }

    public function getMysql()
    {
        $leftMysql = $this->left->getMysql();
        $rightMysql = $this->right->getMysql();

        return "({$leftMysql} {$this->operator} {$rightMysql})";
    }
}
