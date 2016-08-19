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
 * @author Anthony Liu <aliu@datto.com>
 * @license http://www.gnu.org/licenses/lgpl-3.0.html LGPL-3.0
 * @copyright 2016 Datto, Inc.
 */

namespace Datto\Cinnabari;

use Datto\Cinnabari\Compiler\DeleteCompiler;
use Datto\Cinnabari\Compiler\GetCompiler;
use Datto\Cinnabari\Compiler\InsertCompiler;
use Datto\Cinnabari\Compiler\SetCompiler;
use Datto\Cinnabari\Exception\CompilerException;
use Datto\Cinnabari\Php\Output;
use Datto\PhpTypeInferer\TypeInferer;

/**
 * Class Compiler
 * @package Datto\Cinnabari
 */
class Compiler
{
    private static $TYPE_GET = 1;
    private static $TYPE_DELETE = 2;
    private static $TYPE_SET = 3;
    private static $TYPE_INSERT = 4;

    private $getCompiler;
    private $deleteCompiler;
    private $setCompiler;
    private $insertCompiler;
    private $translator;

    private static $signatures = null;

    public function __construct($schema)
    {
        $this->getCompiler = new GetCompiler();
        $this->deleteCompiler = new DeleteCompiler();
        $this->setCompiler = new SetCompiler();
        $this->insertCompiler = new InsertCompiler();
        $this->translator = new Translator($schema);
    }
    
    public static function getSignatures()
    {
        if (!isset(self::$signatures)) {
            $anythingToList = array(
                array('arguments' => array(Output::TYPE_BOOLEAN), 'return' => 'list'),
                array('arguments' => array(Output::TYPE_INTEGER), 'return' => 'list'),
                array('arguments' => array(Output::TYPE_FLOAT), 'return' => 'list'),
                array('arguments' => array(Output::TYPE_STRING), 'return' => 'list')
            );
            $aggregator = array(
                array('arguments' => array(Output::TYPE_INTEGER), 'return' => Output::TYPE_FLOAT),
                array('arguments' => array(Output::TYPE_FLOAT), 'return' => Output::TYPE_FLOAT)
            );
            $unaryBoolean = array(
                array('arguments' => array(Output::TYPE_BOOLEAN), 'return' => Output::TYPE_BOOLEAN)
            );
            $plusSignature = array(
                array(
                    'arguments' => array(Output::TYPE_INTEGER, Output::TYPE_INTEGER),
                    'return' => Output::TYPE_INTEGER
                ),
                array(
                    'arguments' => array(Output::TYPE_FLOAT, Output::TYPE_INTEGER),
                    'return' => Output::TYPE_FLOAT
                ),
                array(
                    'arguments' => array(Output::TYPE_INTEGER, Output::TYPE_FLOAT),
                    'return' => Output::TYPE_FLOAT
                ),
                array(
                    'arguments' => array(Output::TYPE_FLOAT, Output::TYPE_FLOAT),
                    'return' => Output::TYPE_FLOAT
                ),
                array(
                    'arguments' => array(Output::TYPE_STRING, Output::TYPE_STRING),
                    'return' => Output::TYPE_STRING
                )
            );
            $numeric = array(
                array(
                    'arguments' => array(Output::TYPE_INTEGER, Output::TYPE_INTEGER),
                    'return' => Output::TYPE_INTEGER
                ),
                array(
                    'arguments' => array(Output::TYPE_FLOAT, Output::TYPE_INTEGER),
                    'return' => Output::TYPE_FLOAT
                ),
                array(
                    'arguments' => array(Output::TYPE_INTEGER, Output::TYPE_FLOAT),
                    'return' => Output::TYPE_FLOAT
                ),
                array(
                    'arguments' => array(Output::TYPE_FLOAT, Output::TYPE_FLOAT),
                    'return' => Output::TYPE_FLOAT
                )
            );
            $strictComparison = array(
                array(
                    'arguments' => array(Output::TYPE_BOOLEAN, Output::TYPE_BOOLEAN),
                    'return' => Output::TYPE_BOOLEAN
                ),
                array(
                    'arguments' => array(Output::TYPE_INTEGER, Output::TYPE_INTEGER),
                    'return' => Output::TYPE_BOOLEAN
                ),
                array(
                    'arguments' => array(Output::TYPE_FLOAT, Output::TYPE_FLOAT),
                    'return' => Output::TYPE_BOOLEAN
                ),
                array(
                    'arguments' => array(Output::TYPE_STRING, Output::TYPE_STRING),
                    'return' => Output::TYPE_BOOLEAN
                )
            );
            $binaryBoolean = array(
                array(
                    'arguments' => array(Output::TYPE_BOOLEAN, Output::TYPE_BOOLEAN),
                    'return' => Output::TYPE_BOOLEAN
                )
            );
            $comparison = array(
                array(
                    'arguments' => array(Output::TYPE_INTEGER, Output::TYPE_INTEGER),
                    'return' => Output::TYPE_BOOLEAN
                ),
                array(
                    'arguments' => array(Output::TYPE_FLOAT, Output::TYPE_INTEGER),
                    'return' => Output::TYPE_BOOLEAN
                ),
                array(
                    'arguments' => array(Output::TYPE_INTEGER, Output::TYPE_FLOAT),
                    'return' => Output::TYPE_BOOLEAN
                ),
                array(
                    'arguments' => array(Output::TYPE_FLOAT, Output::TYPE_FLOAT),
                    'return' => Output::TYPE_BOOLEAN
                ),
                array(
                    'arguments' => array(Output::TYPE_STRING, Output::TYPE_STRING),
                    'return' => Output::TYPE_BOOLEAN
                )
            );
            $stringFunction = array(
                array(
                    'arguments' => array(Output::TYPE_STRING),
                    'return' => Output::TYPE_STRING
                )
            );
            self::$signatures = array(
                'get' => $anythingToList,
                'average' => $aggregator,
                'sum' => $aggregator,
                'min' => $aggregator,
                'max' => $aggregator,
                'filter' => array(
                    array(
                        'arguments' => array(Output::TYPE_BOOLEAN),
                        'return' => 'list'
                    )
                ),
                'sort' => $anythingToList,
                'slice' => array(
                    array(
                        'arguments' => array(Output::TYPE_INTEGER, Output::TYPE_INTEGER),
                        'return' => 'list'
                    )
                ),
                'not' => $unaryBoolean,
                'plus' => $plusSignature,
                'minus' => $numeric,
                'times' => $numeric,
                'divides' => $numeric,
                'equal' => $strictComparison,
                'and' => $binaryBoolean,
                'or' => $binaryBoolean,
                'notEqual' => $strictComparison,
                'less' => $comparison,
                'lessEqual' => $comparison,
                'greater' => $comparison,
                'greaterEqual' => $comparison,
                'match' => array(
                    array(
                        'arguments' => array(Output::TYPE_STRING, Output::TYPE_STRING),
                        'return' => Output::TYPE_BOOLEAN
                    )
                ),
                'lowercase' => $stringFunction,
                'uppercase' => $stringFunction,
                'substring' => array(
                    array(
                        'arguments' => array(Output::TYPE_STRING, Output::TYPE_INTEGER, Output::TYPE_INTEGER),
                        'return' => Output::TYPE_STRING
                    )
                ),
                'length' => array(
                    array(
                        'arguments' => array(Output::TYPE_STRING),
                        'return' => Output::TYPE_INTEGER
                    )
                ),

                // TODO: this function is used internally by the type inferer to handle sets/inserts
                'assign' => $strictComparison
            );
        }

        return self::$signatures;
    }

    public function compile($request)
    {
        $queryType = self::getQueryType($request, $topLevelFunction);

        switch ($queryType) {
            case self::$TYPE_GET:
                $translatedRequest = $this->translator->translateIgnoringObjects($request);
                $types = self::getTypes($translatedRequest);
                return $this->getCompiler->compile($topLevelFunction, $translatedRequest, $types);

            case self::$TYPE_DELETE:
                $translatedRequest = $this->translator->translateIgnoringObjects($request);
                $types = self::getTypes($translatedRequest);
                return $this->deleteCompiler->compile($topLevelFunction, $translatedRequest, $types);

            case self::$TYPE_SET:
                $translatedRequest = $this->translator->translateIncludingObjects($request);
                $types = self::getTypes($translatedRequest);
                return $this->setCompiler->compile($topLevelFunction, $translatedRequest, $types);

            case self::$TYPE_INSERT:
                $translatedRequest = $this->translator->translateIncludingObjects($request);
                $types = self::getTypes($translatedRequest);
                return $this->insertCompiler->compile($topLevelFunction, $translatedRequest, $types);
        }
        
        return null;
    }

    private static function getQueryType($request, &$topLevelFunction)
    {
        if (isset($request) && (count($request) >= 1)) {
            $firstToken = reset($request);
            if (count($firstToken) >= 3) {
                list($tokenType, $functionName, ) = $firstToken;

                if ($tokenType === Parser::TYPE_FUNCTION) {
                    $topLevelFunction = $functionName;

                    switch ($functionName) {
                        case 'get':
                        case 'count':
                        case 'average':
                        case 'sum':
                        case 'min':
                        case 'max':
                            return self::$TYPE_GET;
                            
                        case 'delete':
                            return self::$TYPE_DELETE;
                            
                        case 'set':
                            return self::$TYPE_SET;

                        case 'insert':
                            return self::$TYPE_INSERT;
                    }
                }
            }
        }
    
        $topLevelFunction = null;
        throw CompilerException::unknownRequestType($request);
    }

    private static function getTypes($translatedRequest)
    {
        self::extractExpression($translatedRequest, $expressions);
        $typeInferer = new TypeInferer(self::getSignatures());
        return $typeInferer->infer($expressions);
    }

    private static function extractExpression($requestArray, &$expressions)
    {
        if (!isset($expressions)) {
            $expressions = array();
        }
        $localExpressions = array();

        foreach ($requestArray as $request) {
            list($tokenType, $token) = each($request);

            switch ($tokenType) {
                case Translator::TYPE_FUNCTION:
                    $arguments = array();
                    foreach ($token['arguments'] as $argument) {
                        $argumentExpressions = self::extractExpression($argument, $expressions);
                        if (count($argumentExpressions) > 0) {
                            $expression = self::extractExpression($argument, $expressions);
                            $arguments[] = end($expression);
                        }
                    }
                    if (count($arguments) > 0) {
                        $expressions[] = array(
                            'name' => $token['function'],
                            'type' => 'function',
                            'arguments' => $arguments
                        );
                    }
                    break;

                case Translator::TYPE_PARAMETER:
                    $localExpressions[] = array(
                        'name' => $token,
                        'type' => 'parameter'
                    );
                    break;

                case Translator::TYPE_VALUE:
                    $localExpressions[] = array(
                        'name' => $token['type'],
                        'type' => 'primitive'
                    );
                    break;

                case Translator::TYPE_LIST:
                    foreach ($token as $pair) {
                        $left = self::extractExpression($pair['property'], $expressions);
                        $right = self::extractExpression($pair['value'], $expressions);
                        if ((count($left) > 0) && (count($right) > 0)) {
                            $expressions[] = array(
                                'name' => 'assign',
                                'type' => 'function',
                                'arguments' => array($left[0], $right[0])
                            );
                        }
                    }
                    break;
            }
        }

        return $localExpressions;
    }
}
