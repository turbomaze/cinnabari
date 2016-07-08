<?php

namespace Datto\Cinnabari;

use Datto\Cinnabari\Format\Arguments;
use Datto\Cinnabari\Mysql\Expression\Column;
use Datto\Cinnabari\Mysql\Expression\OperatorBinary;
use Datto\Cinnabari\Mysql\Expression\OperatorNot;
use Datto\Cinnabari\Mysql\Expression\Parameter;
use Datto\Cinnabari\Mysql\Select;
use Datto\Cinnabari\Php\Output;
use Datto\Cinnabari\TypeInferer;

class Compiler
{
    /** @var Arguments */
    private $arguments;

    /** @var Select */
    private $mysql;

    /** @var string */
    private $phpOutput;

    /** @var function signature */
    private $functionSignatures;

    /** @var array */
    private static $binaryOperatorMap = array(
        'equal' => '<=>', 'notEqual' => '<=>',
        'and' => 'AND', 'or' => 'OR',
        'greater' => '>', 'less' => '<',
        'greaterEqual' => '>=', 'lessEqual' => '<=',
        'plus' => '+', 'minus' => '-', 'times' => '*', 'divides' => '/',
        'match' => 'REGEXP BINARY'
    );

    public function __construct()
    {
        // function signatures
        $numericSignatures = array(
            array(
                'returnType' => Output::TYPE_INTEGER,
                'argumentTypes' => array(Output::TYPE_INTEGER, Output::TYPE_INTEGER)
            ),
            array(
                'returnType' => Output::TYPE_FLOAT,
                'argumentTypes' => array(Output::TYPE_INTEGER, Output::TYPE_FLOAT)
            ),
            array(
                'returnType' => Output::TYPE_FLOAT,
                'argumentTypes' => array(Output::TYPE_FLOAT, Output::TYPE_INTEGER)
            ),
            array(
                'returnType' => Output::TYPE_FLOAT,
                'argumentTypes' => array(Output::TYPE_FLOAT, Output::TYPE_FLOAT)
            )
        );

        $mapSignature = array(
            array(
                'returnType' => Output::TYPE_NULL,
                'argumentTypes' => array(Output::TYPE_NULL)
            )
        );

        $filterSignature = array(
            array(
                'returnType' => Output::TYPE_NULL,
                'argumentTypes' => array(Output::TYPE_BOOLEAN)
            )
        );

        $updateSignature = array(
            array(
                'returnType' => Output::TYPE_NULL,
                'argumentTypes' => array(Output::TYPE_ARRAY, Output::TYPE_ARRAY)
            )
        );

        $binaryBooleanSignatures = $numericSignatures;
        $comparisonSignatures = array(
            array(
                'returnType' => Output::TYPE_BOOLEAN,
                'argumentTypes' => array(Output::TYPE_STRING, Output::TYPE_STRING),
            ),
            array(
                'returnType' => Output::TYPE_BOOLEAN,
                'argumentTypes' => array(Output::TYPE_INTEGER, Output::TYPE_INTEGER),
            ),
            array(
                'returnType' => Output::TYPE_BOOLEAN,
                'argumentTypes' => array(Output::TYPE_INTEGER, Output::TYPE_FLOAT),
            ),
            array(
                'returnType' => Output::TYPE_BOOLEAN,
                'argumentTypes' => array(Output::TYPE_FLOAT, Output::TYPE_INTEGER),
            ),
            array(
                'returnType' => Output::TYPE_BOOLEAN,
                'argumentTypes' => array(Output::TYPE_FLOAT, Output::TYPE_FLOAT),
            )
        );
        $equalitySignatures = $comparisonSignatures;


        // define what all of the functions in the Datto API look like
        $functionSignatures = array(
            'filter' => $filterSignature,
            'map' => $mapSignature,
            'update' => $updateSignature,
            'plus' => $numericSignatures,
            'equal' => $equalitySignatures,
            'less' => $comparisonSignatures
        );

        $this->typeInferer = new TypeInferer($functionSignatures);
    }

    public function compile($translation, $arguments)
    {
        $this->mysql = new Select();
        $this->arguments = new Arguments($arguments);
        $this->phpOutput = null;

        $annotatedTree = $this->typeInferer->getTypes($translation);

        // solve recursively here
        $this->buildStructure($annotatedTree, null);

        $this->phpOutput = Output::getList(0, false, true, $this->phpOutput);
        $mysql = $this->mysql->getMysql();
        $formatInput = $this->arguments->getPhp();

        if (!isset($mysql, $formatInput, $this->phpOutput)) {
            return null;
        }

        return array($mysql, $formatInput, $this->phpOutput);
    }

    public function buildStructure($tree, $context)
    {
        $structures = array();

        foreach ($tree as $key => $branch) {
            list($type, $value) = each($branch);

            $builtKids = array();
            if (is_array($value) && array_key_exists('arguments', $value)) {
                foreach ($value['arguments'] as $argumentIndex => $kid) {
                    $builtKids[] = $this->buildStructure($kid, $context);
                }
            }

            // build this branch based off of its children
            switch ($type) {
                case Translator::TYPE_TABLE:
                    // the table entry point
                    $context = $this->mysql->setTable($value['table']);
                    $idColumn = new Column($context, $value['id'], Output::TYPE_INTEGER, false);
                    $this->mysql->addValue($idColumn->getMysql());
                    $structures[] = true;
                    break;

                case Translator::TYPE_JOIN:
                    $context = $this->mysql->addJoin(
                        $context,
                        $value['tableB'],
                        $value['expression'],
                        $value['hasZero'],
                        $value['hasMany']
                    );

                    $structures[] = true;
                    break;

                case Translator::TYPE_PARAMETER:
                    $name = $value;
                    $typeArray = array('f' => 'float', 'i' => 'integer', 's' => 'string');
                    $neededType = $typeArray[$name[0]]; // TODO: type inference
                    $id = $this->arguments->useArgument($name, $neededType);

                    if ($id === null) {
                        echo "ERROR! BAD TYPE\n\n"; // TODO: fix this
                        return false;
                    }
 
                    $structures[] = new Parameter($id);
                    break;

                case Translator::TYPE_VALUE:
                    $columnReference = $value['expression'];
                    $dataType = $value['type'];
                    $isNullable = $value['hasZero'];
                    $structures[] = new Column($context, $columnReference, $dataType, $isNullable);
                    break;

                case Translator::TYPE_OBJECT:
                    $properties = array();
                    foreach ($value as $objectKey => $expression) {
                        $column = end($this->buildStructure($expression, $context));

                        // if it's not an object, then it's a property
                        if ($column !== Translator::TYPE_OBJECT) {
                            $properties[$objectKey] = $this->addValue($column);
                        } else {
                            $properties[$objectKey] = $this->phpOutput;
                        }
                    }
                    $this->phpOutput = Output::getObject($properties);
                    $structures[] = Translator::TYPE_OBJECT;
                    break;

                case Translator::TYPE_ARRAY:
                    // build all children and return list of properties / params
                    $builtElements = array();
                    foreach ($value as $key => $element) {
                        $builtElement = $this->buildStructure($element, $context);
                        $builtElements[] = end($builtElement);
                    }
                    $structures[] = $builtElements;
                    break;

                case Translator::TYPE_FUNCTION:
                    $functionName = $value['function'];
                    $firstArgument = end($builtKids[0]);
                    $secondArgument = (count($builtKids) > 1) ? end($builtKids[1]) : null;

                    switch ($functionName) {
                        case 'filter':
                            $this->mysql->setWhere($firstArgument);
                            $structures[] = true;
                            break;

                        case 'sort':
                            $this->mysql->setOrderBy($firstArgument->getMysql(), true);
                            $structures[] = true;
                            break;

                        case 'update':
                            // expecting two array inputs
                            foreach ($firstArgument as $index => $column) {
                                $parameter = $secondArgument[$index];
                                $this->phpOutput = $this->addUpdate($column, $parameter);
                            }

                            $structures[] = true;
                            break;

                        case 'map':
                            // if it's not an object, then it's a column
                            if ($firstArgument !== Translator::TYPE_OBJECT) {
                                $this->phpOutput = $this->addValue($firstArgument); // TODO: expression support
                            }
                            $structures[] = true;
                            break;

                        case 'not':
                            $structures[] = new OperatorNot($firstArgument);
                            break;

                        case 'notEqual':
                            $operatorSymbol = self::$binaryOperatorMap[$functionName];
                            $structures[] = new OperatorNot(new OperatorBinary(
                                $operatorSymbol,
                                $firstArgument,
                                $secondArgument
                            ));
                            break;

                        default:
                            $operatorSymbol = self::$binaryOperatorMap[$functionName];
                            $structures[] = new OperatorBinary($operatorSymbol, $firstArgument, $secondArgument);
                            break;
                    }
                    break;
            }
        }

        return $structures;
    }

    private function addValue(Column $column)
    {
        $columnId = $this->mysql->addValue($column->getMysql());
        return Output::getValue($columnId, $column->getIsNullable(), $column->getDataType());
    }

    private function addUpdate(Column $column, Parameter $parameter)
    {
        list($columnId,) = $this->mysql->addUpdate($column->getMysql(), $parameter);
        return Output::getValue($columnId, $column->getIsNullable(), $column->getDataType());
    }
}
