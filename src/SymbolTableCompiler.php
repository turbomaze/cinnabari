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

class SymbolTableCompiler
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
        'equal' => '<=>',
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
                list($columnReference, $dataType) = $this->symbols[$value];
                return new Column($dataType, $columnReference);

            case Parser::TYPE_OBJECT:
                $properties = array();
                foreach ($value as $key => $expression) {
                    $column = $this->buildStructure($expression);
                    if ($column !== Parser::TYPE_OBJECT) {
                        $dataType = $column->getDatatype();
                        $columnId = $this->mysql->addValue($column->getMysql());
                        $properties[$key] = Output::getValue($columnId, true, $dataType);
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
                        return true;
                    case 'not':
                        return new OperatorNot($builtKids[0]);
                    default:
                        $operatorSymbol = self::$binaryOperatorMap[$value];
                        return new OperatorBinary($operatorSymbol, $builtKids[0], $builtKids[1]);
                }
                return $value;
        }
    }
}
