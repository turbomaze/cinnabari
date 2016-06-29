<?php

namespace Datto\Cinnabari;

use Datto\Cinnabari\Mysql\Select;
use Datto\Cinnabari\Format\Arguments;
use Datto\Cinnabari\Php\Output;
use Datto\Cinnabari\Mysql\Expression\Column;
use Datto\Cinnabari\Mysql\Expression\Parameter;
use Datto\Cinnabari\Mysql\Expression\AbstractOperatorBinary;
use Datto\Cinnabari\Mysql\Expression\OperatorBinary;
use Datto\Cinnabari\Mysql\Expression\OperatorNot;

class Compiler
{
    /** @var array */
    private $symbols;

    /** @var array */
    private $preamble;

    /** @var Arguments */
    private $arguments;

    /** @var Select */
    private $mysql;

    /** @var string */
    private $phpOutput;

    /** @var array */
    private static $binaryOperatorMap = array(
        'equal' => '<=>', 'notEqual' => '<=>',
        'and' => 'AND', 'or' => 'OR',
        'greater' => '>', 'less' => '<',
        'greaterEqual' => '>=', 'lessEqual' => '<=',
        'plus' => '+', 'minus' => '-', 'times' => '*', 'divides' => '/',
        'match' => 'REGEXP BINARY'
    );

    public function compile($symbols, $preamble, $annotatedTree, $arguments)
    {
        $this->symbols = $symbols;
        $this->preamble = $preamble;

        $this->mysql = new Select();
        $this->arguments = new Arguments($arguments);
        $this->phpOutput = null;

        $this->initializeMySQL($preamble);

        // solve recursively here
        array_map(array($this, 'buildStructure'), $annotatedTree);
        $this->phpOutput = Output::getList(0, false, true, $this->phpOutput);

        $mysql = $this->mysql->getMysql();
        $formatInput = $this->arguments->getPhp();

        if (!isset($mysql, $formatInput, $this->phpOutput)) {
            return null;
        }

        return array($mysql, $formatInput, $this->phpOutput);
    }

    private function initializeMySQL()
    {
        // the table entry point
        $this->mysql->setTable($this->preamble[0][1]);
        $idReference = $this->symbols[$this->preamble[0][2]];
        $this->mysql->addValue($idReference);

        // the joins
        foreach (array_slice($this->preamble, 1) as $token) {
            $joinToken = $token[0];
            $joinId= $token[1];
            $join = $this->symbols[$joinId];
            $this->mysql->insertIntoTables($join);
        }
    }

    public function buildStructure($branch) {
        $type = $branch[0]; 
        $value = $branch[1];
        $kids = array_slice($branch, 2);
        $builtKids = array_map(array($this, 'buildStructure'), $kids);

        // build this branch based off of its children
        switch ($type) {
            case Parser::TYPE_PARAMETER:
                list($name, $neededType) = $this->symbols[$value];
                $id = $this->arguments->useArgument($name, $neededType);
                return new Parameter($id);

            case Parser::TYPE_VALUE:
                list($columnReference, $dataType, $isNullable) = $this->symbols[$value];
                return new Column($dataType, $columnReference, $isNullable);

            case Parser::TYPE_OBJECT:
                $properties = array();
                foreach ($value as $key => $expression) {
                    $column = $this->buildStructure($expression);
                    // if it's not an object, then it's a property
                    if ($column !== Parser::TYPE_OBJECT) {
                        $properties[$key] = $this->addValue($column);
                    } else {
                        $properties[$key] = $this->phpOutput;
                    }
                }
                $this->phpOutput = Output::getObject($properties);
                return Parser::TYPE_OBJECT;

            case Parser::TYPE_FUNCTION:
                switch ($value) {
                    case 'filter':
                        $this->mysql->setWhere($builtKids[0]);
                        return true;
                    case 'sort':
                        $this->mysql->setOrderBy($builtKids[0]->getMysql(), true);
                        return true;
                    case 'map':
                        // if it's not an object, then it's a column
                        if ($builtKids[0] !== Parser::TYPE_OBJECT) {
                            $this->phpOutput = $this->addValue($builtKids[0]);
                        }
                        return true;
                    case 'not':
                        return new OperatorNot($builtKids[0]);
                    case 'notEqual':
                        $operatorSymbol = self::$binaryOperatorMap[$value];
                        return new OperatorNot(new OperatorBinary(
                            $operatorSymbol, $builtKids[0], $builtKids[1]
                        ));
                    default:
                        $operatorSymbol = self::$binaryOperatorMap[$value];
                        return new OperatorBinary($operatorSymbol, $builtKids[0], $builtKids[1]);
                }
                return $value;
        }
    }

    private function addValue($column)
    {
        $columnId = $this->mysql->addValue($column->getMysql());
        return Output::getValue($columnId, $column->getIsNullable(), $column->getDatatype());
    }
}
