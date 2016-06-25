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

namespace Datto\Cinnabari;

abstract class Grammar
{
    const TYPE_METHOD = 1;
    const TYPE_AND = 2;
    const TYPE_OR = 3;
    const TYPE_REPEAT = 4;

    /** @var array */
    private $rules;

    public function __construct($rules)
    {
        $this->rules = $rules;
    }

    abstract protected function getState();

    abstract protected function setState($state);

    public function applyRule($rule)
    {
        $definition = $this->rules[$rule];

        $type = array_shift($definition);

        switch ($type) {
            case self::TYPE_METHOD:
                return $this->getMethod($rule, $definition[0]);

            case self::TYPE_AND:
                return $this->getAnd($definition);

            case self::TYPE_OR:
                return $this->getOr($definition);

            case self::TYPE_REPEAT:
                return $this->getRepeat($definition[0], $definition[1], $definition[2]);

            default:
                $ruleName = self::quote($rule);
                $message = "Unknown rule {$ruleName}";
                $state = $this->getState();
                throw new Exception(2, $state, $message);
        }
    }

    private function getMethod($rule, $method)
    {
        $callable = array($this, $method);

        if (!is_callable($callable)) {
            $callableName = json_encode($callable);
            $message = "Unknown callable {$callableName}";
            throw new Exception(2, null, $message);
        }

        $state = $this->getState();

        if (call_user_func($callable)) {
            return true;
        }

        $this->setState($state);

        $message = "Expected a {$rule}";
        throw new Exception(1, null, $message);
    }

    private function getAnd($rules)
    {
        $state = $this->getState();

        try {
            foreach ($rules as $rule) {
                $this->applyRule($rule);
            }
        } catch (Exception $exception) {
            $this->setState($state);
            throw $exception;
        }

        return true;
    }

    private function getOr($rules)
    {
        foreach ($rules as $rule) {
            try {
                $this->applyRule($rule);
            } catch (Exception $exception) {
                continue;
            }

            return true;
        }

        $state = $this->getState();
        $rulesName = implode(' or ', array_map('self::quote', $rules));
        throw new Exception(1, $state, "Expected {$rulesName}");
    }

    private function getRepeat($rule, $min, $max)
    {
        if (!is_int($min)) {
            $ruleName = self::quote($rule);
            $minName = self::quote($min);
            $message = "In the rule {$ruleName}, the minimum value ({$minName}) must be an integer.";
            throw new Exception(2, array($rule, $min), $message);
        }

        if ($min < 0) {
            $ruleName = self::quote($rule);
            $message = "In the rule {$ruleName}, the minimum value ({$min}) must be zero or more.";
            throw new Exception(2, array($rule, $min), $message);
        }

        if ($max !== null) {
            if (!is_int($max)) {
                $ruleName = self::quote($rule);
                $maxName = self::quote($max);
                $message = "In the rule {$ruleName}, the maximum value ({$maxName}) must be an integer.";
                throw new Exception(2, array($rule, $max), $message);
            }

            if ($max < 1) {
                $ruleName = self::quote($rule);
                $message = "In the rule {$ruleName}, the maximum value ({$max}) must be greater than zero.";
                throw new Exception(2, array($rule, $max), $message);
            }

            if ($max < $min) {
                $ruleName = self::quote($rule);
                $message = "In the rule {$ruleName}, the minimum value ({$min}) must be less than or equal to the maximum value ({$max}).";
                throw new Exception(2, array($rule, $min), $message);
            }
        }

        $state = $this->getState();

        for ($i = 0; $i < $max; ++$i) {
            try {
                $this->applyRule($rule);
            } catch (Exception $exception) {
                break;
            }
        }

        if ($i < $min) {
            $this->setState($state);
            $ruleName = self::quote($rule);

            $minMultiplicativeNumber = self::getMultiplicativeNumber($min);

            if ($i === 0) {
                $foundPhrase = "no repetitions";
            } elseif ($i === 1) {
                $foundPhrase = "only one repetition";
            } else {
                $iCardinal = self::getCardinalNumber($i);
                $foundPhrase = "only {$iCardinal} repetitions";
            }

            $message = "Expected {$ruleName} to appear at least {$minMultiplicativeNumber}, but found {$foundPhrase}.";

            throw new Exception(1, $state, $message);
        }

        return true;
    }

    private static function getMultiplicativeNumber($n)
    {
        switch ($n) {
            case 1:
                return 'once';

            case 2:
                return 'twice';

            default:
                $cardinal = self::getCardinalNumber($n);
                return "{$cardinal} times";
        }
    }

    private static function getCardinalNumber($n)
    {
        switch ($n) {
            case 0:
                return 'zero';

            case 1:
                return 'one';

            case 2:
                return 'two';

            case 3:
                return 'three';

            case 4:
                return 'four';

            case 5:
                return 'five';

            case 6:
                return 'six';

            case 7:
                return 'seven';

            case 8:
                return 'eight';

            case 9:
                return 'nine';

            default:
                return (string)$n;
        }
    }

    protected static function quote($value)
    {
        return json_encode($value);
    }
}
