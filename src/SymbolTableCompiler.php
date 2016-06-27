<?php

namespace Datto\Cinnabari;

use Datto\Cinnabari\Mysql\Select;
use Datto\Cinnabari\Format\Arguments;
use Datto\Cinnabari\Php\Output;
use Datto\Cinnabari\Mysql\Expression\Column;
use Datto\Cinnabari\Mysql\Expression\OperatorAnd;
use Datto\Cinnabari\Mysql\Expression\OperatorDivides;
use Datto\Cinnabari\Mysql\Expression\OperatorEqual;
use Datto\Cinnabari\Mysql\Expression\OperatorGreater;
use Datto\Cinnabari\Mysql\Expression\OperatorGreaterEqual;
use Datto\Cinnabari\Mysql\Expression\OperatorLess;
use Datto\Cinnabari\Mysql\Expression\OperatorLessEqual;
use Datto\Cinnabari\Mysql\Expression\OperatorMinus;
use Datto\Cinnabari\Mysql\Expression\OperatorNot;
use Datto\Cinnabari\Mysql\Expression\OperatorOr;
use Datto\Cinnabari\Mysql\Expression\OperatorPlus;
use Datto\Cinnabari\Mysql\Expression\OperatorRegexpBinary;
use Datto\Cinnabari\Mysql\Expression\OperatorTimes;
use Datto\Cinnabari\Mysql\Expression\Parameter;

class SymbolTableCompiler
{
    /** @var Schema */
    private $schema;

    /** @var array */
    private $symbols;

    /** @var array */
    private $preamble;

    /** @var array */
    private $request;

    /** @var Arguments */
    private $arguments;

    /** @var Select */
    private $mysql;

    /** @var string */
    private $phpOutput;

    public function __construct(Schema $schema)
    {
        $this->schema = $schema;
    }

    public function compile($symbols, $preamble, $annotatedTree, $arguments)
    {
        $this->symbols = $symbols;
        $this->preamble = $preamble;
        $this->request = $annotatedTree;

        $this->mysql = new Select();
        $this->arguments = new Arguments($arguments);
        $this->phpOutput = null;

        $this->initializeMySQL($preamble);

        if (!$this->getArrayProperty()) {
            return null;
        }

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

    private function getArrayProperty()
    {
        // get functions
        $this->getOptionalFilterFunction();
        $this->getOptionalSortFunction();

        $this->request = reset($this->request);

        // process map
        $this->readMap();

        $idAlias = 0; // TODO
        $this->phpOutput = Output::getList($idAlias, false, true, $this->phpOutput);
        return true;
    }

    private function getOptionalFilterFunction()
    {
        $token = current($this->request);

        if (!self::scanFunction($token, $name, $arguments)) {
            return false;
        }

        if ($name !== 'filter') {
            return false;
        }

        if (!$this->getExpression($arguments[0], $where)) {
            return false;
        }

        $this->mysql->setWhere($where);

        array_shift($this->request);
        return true;
    }

    private function getExpression($token, &$expression)
    {
        return $this->getBooleanExpression($token, $expression)
            || $this->getNumericExpression($token, $expression)
            || $this->getStringExpression($token, $expression);
    }

    private function getBooleanExpression($token, &$output)
    {
        list($type, $name) = $token;

        switch ($type) {
            case Parser::TYPE_PARAMETER:
                return $this->getBooleanParameter($name, $output);

            case Parser::TYPE_VALUE:
                return $this->getBooleanProperty($name, $output);

            case Parser::TYPE_FUNCTION:
                $arguments = array_slice($token, 2);
                return $this->getBooleanFunction($name, $arguments, $output);

            default:
                return false;
        }
    }

    private function getBooleanParameter($name, &$output)
    {
        return $this->getParameter($name, 'boolean', $output);
    }

    private function getParameter($name, $type, &$output)
    {
        $id = $this->arguments->useArgument($name, $type);

        if ($id === null) {
            return false;
        }

        $output = new Parameter($id);
        return true;
    }

    private function getBooleanProperty($name, &$output)
    {
        return $this->getProperty($name, Output::TYPE_BOOLEAN, $output);
    }

    private function getBooleanFunction($name, $arguments, &$output)
    {
        $countArguments = count($arguments);

        if ($countArguments === 1) {
            $argument = current($arguments);
            return $this->getBooleanUnaryFunction($name, $argument, $output);
        }

        if ($countArguments === 2) {
            list($argumentA, $argumentB) = $arguments;
            return $this->getBooleanBinaryFunction($name, $argumentA, $argumentB, $output);
        }

        return false;
    }

    private function getBooleanUnaryFunction($name, $argument, &$expression)
    {
        if ($name !== 'not') {
            return false;
        }

        if (!$this->getBooleanExpression($argument, $childExpression)) {
            return false;
        }

        $expression = new OperatorNot($childExpression);
        return true;
    }

    private function getBooleanBinaryFunction($name, $argumentA, $argumentB, &$expression)
    {
        switch ($name) {
            case 'equal':
                return $this->getEqualFunction($argumentA, $argumentB, $expression);

            case 'and':
                return $this->getAndFunction($argumentA, $argumentB, $expression);

            case 'or':
                return $this->getOrFunction($argumentA, $argumentB, $expression);

            case 'notEqual':
                return $this->getNotEqualFunction($argumentA, $argumentB, $expression);

            case 'less':
                return $this->getLessFunction($argumentA, $argumentB, $expression);

            case 'lessEqual':
                return $this->getLessEqualFunction($argumentA, $argumentB, $expression);

            case 'greater':
                return $this->getGreaterFunction($argumentA, $argumentB, $expression);

            case 'greaterEqual':
                return $this->getGreaterEqualFunction($argumentA, $argumentB, $expression);

            case 'match':
                return $this->getMatchFunction($argumentA, $argumentB, $expression);

            default:
                return false;
        }
    }

    private function getEqualFunction($argumentA, $argumentB, &$expression)
    {
        if (
            (
                $this->getBooleanExpression($argumentA, $expressionA) &&
                $this->getBooleanExpression($argumentB, $expressionB)
            ) || (
                $this->getNumericExpression($argumentA, $expressionA) &&
                $this->getNumericExpression($argumentB, $expressionB)
            ) || (
                $this->getStringExpression($argumentA, $expressionA) &&
                $this->getStringExpression($argumentB, $expressionB)
            )
        ) {
            $expression = new OperatorEqual($expressionA, $expressionB);
            return true;
        }

        return false;
    }

    private function getAndFunction($argumentA, $argumentB, &$expression)
    {
        if (
            !$this->getBooleanExpression($argumentA, $outputA) ||
            !$this->getBooleanExpression($argumentB, $outputB)
        ) {
            return false;
        }

        $expression = new OperatorAnd($outputA, $outputB);
        return true;
    }

    private function getOrFunction($argumentA, $argumentB, &$expression)
    {
        if (
            !$this->getBooleanExpression($argumentA, $outputA) ||
            !$this->getBooleanExpression($argumentB, $outputB)
        ) {
            return false;
        }

        $expression = new OperatorOr($outputA, $outputB);
        return true;
    }

    private function getNotEqualFunction($argumentA, $argumentB, &$expression)
    {
        if (!$this->getEqualFunction($argumentA, $argumentB, $equalExpression)) {
            return false;
        }

        $expression = new OperatorNot($equalExpression);
        return true;
    }

    private function getLessFunction($argumentA, $argumentB, &$expression)
    {
        if (
            (
                $this->getNumericExpression($argumentA, $expressionA) &&
                $this->getNumericExpression($argumentB, $expressionB)
            ) || (
                $this->getStringExpression($argumentA, $expressionA) &&
                $this->getStringExpression($argumentB, $expressionB)
            )
        ) {
            $expression = new OperatorLess($expressionA, $expressionB);
            return true;
        }

        return false;
    }

    private function getLessEqualFunction($argumentA, $argumentB, &$expression)
    {
        if (
            (
                $this->getNumericExpression($argumentA, $expressionA) &&
                $this->getNumericExpression($argumentB, $expressionB)
            ) || (
                $this->getStringExpression($argumentA, $expressionA) &&
                $this->getStringExpression($argumentB, $expressionB)
            )
        ) {
            $expression = new OperatorLessEqual($expressionA, $expressionB);
            return true;
        }

        return false;
    }

    private function getGreaterFunction($argumentA, $argumentB, &$expression)
    {
        if (
            (
                $this->getNumericExpression($argumentA, $expressionA) &&
                $this->getNumericExpression($argumentB, $expressionB)
            ) || (
                $this->getStringExpression($argumentA, $expressionA) &&
                $this->getStringExpression($argumentB, $expressionB)
            )
        ) {
            $expression = new OperatorGreater($expressionA, $expressionB);
            return true;
        }

        return false;
    }

    private function getGreaterEqualFunction($argumentA, $argumentB, &$expression)
    {
        if (
            (
                $this->getNumericExpression($argumentA, $expressionA) &&
                $this->getNumericExpression($argumentB, $expressionB)
            ) || (
                $this->getStringExpression($argumentA, $expressionA) &&
                $this->getStringExpression($argumentB, $expressionB)
            )
        ) {
            $expression = new OperatorGreaterEqual($expressionA, $expressionB);
            return true;
        }

        return false;
    }

    private function getMatchFunction($property, $pattern, &$expression)
    {
        if (!$this->getStringProperty($property[1], $argumentExpression)) {
            return false;
        }

        if (($pattern[0] !== Parser::TYPE_PARAMETER) || !$this->getStringParameter($pattern[1], $patternExpression)) {
            return false;
        }

        $expression = new OperatorRegexpBinary($argumentExpression, $patternExpression);
        return true;
    }

    private function getNumericExpression($token, &$output)
    {
        list($type, $name) = $token;

        switch ($type) {
            case Parser::TYPE_PARAMETER:
                return $this->getNumericParameter($name, $output);

            case Parser::TYPE_VALUE:
                return $this->getNumericProperty($name, $output);

            case Parser::TYPE_FUNCTION:
                $arguments = array_slice($token, 2);
                return $this->getNumericBinaryFunction(
                    $name, $arguments[0], $arguments[1], $output
                );

            default:
                return false;
        }
    }

    private function getNumericParameter($name, &$output)
    {
        return $this->getParameter($name, 'integer', $output)
        || $this->getParameter($name, 'double', $output);
    }

    private function getNumericProperty($propertyId, &$output)
    {
        return $this->getProperty($propertyId, Output::TYPE_INTEGER, $output)
        || $this->getProperty($propertyId, Output::TYPE_FLOAT, $output);
    }

    private function getNumericBinaryFunction($name, $argumentA, $argumentB, &$expression)
    {
        if (
            !$this->getNumericExpression($argumentA, $expressionA) ||
            !$this->getNumericExpression($argumentB, $expressionB)
        ) {
            return false;
        }

        switch ($name) {
            case 'plus':
                $expression = new OperatorPlus($expressionA, $expressionB);
                return true;

            case 'minus':
                $expression = new OperatorMinus($expressionA, $expressionB);
                return true;

            case 'times':
                $expression = new OperatorTimes($expressionA, $expressionB);
                return true;

            case 'divides':
                $expression = new OperatorDivides($expressionA, $expressionB);
                return true;

            default:
                return false;
        }
    }

    private function getStringExpression($token, &$output)
    {
        $type = $token[0];

        switch ($type) {
            case Parser::TYPE_PARAMETER:
                return $this->getStringParameter($token[1], $output);

            case Parser::TYPE_VALUE:
                return $this->getStringProperty($token[1], $output);

            default:
                return false;
        }
    }

    private function getStringParameter($name, &$output)
    {
        return $this->getParameter($name, 'string', $output);
    }

    private function getStringProperty($propertyId, &$output)
    {
        return $this->getProperty($propertyId, Output::TYPE_STRING, $output);
    }

    private function getProperty($propertyId, $neededType, &$output)
    {
        list($columnReference, $type) = $this->symbols[$propertyId];
        $output = new Column($columnReference);
        return true;
    }

    private function readExpression()
    {
        $token = $this->request;

        $type = $token[0];

        switch ($type) {
            case Parser::TYPE_VALUE:
                return $this->readProperty();

            case Parser::TYPE_OBJECT:
                return $this->readObject();

            case Parser::TYPE_FUNCTION:
                return $this->readMap();

            default:
                return false;
        }
    }

    private function readProperty()
    {
        if (!self::scanProperty($this->request, $property)) {
            return false;
        }

        list($columnReference, $type) = $this->symbols[$property];
        $columnId = $this->mysql->addValue($columnReference);
        $this->phpOutput = Output::getValue($columnId, true, $type);
        return true;
    }

    private function readObject()
    {
        if (!self::scanObject($this->request, $object)) {
            return false;
        }

        $properties = array();

        foreach ($object as $label => $this->request) {
            if (!$this->readExpression()) {
                return false;
            }

            $properties[$label] = $this->phpOutput;
        }

        $this->phpOutput = Output::getObject($properties);
        return true;
    }

    private function readMap()
    {
        if (!self::scanFunction($this->request, $name, $arguments)) {
            return false;
        }

        if ($name !== 'map') {
            return false;
        }

        $this->request = reset($arguments);

        return $this->readExpression();
    }

    private function getOptionalSortFunction()
    {
        $token = reset($this->request);

        if (!self::scanFunction($token, $name, $arguments)) {
            return false;
        }

        if ($name !== 'sort') {
            return false;
        }

        $token = $arguments[0];

        if (!$this->scanProperty($token, $propertyId)) {
            return false;
        }

        list($columnReference, $type) = $this->symbols[$propertyId];
        $this->mysql->setOrderBy($columnReference, true);
        array_shift($this->request);
        return true;
    }

    private static function scanParameter($token, &$name)
    {
        if (!self::isParameterToken($token)) {
            return false;
        }

        $name = $token[1];
        return true;
    }

    private static function scanProperty($token, &$propertyId)
    {
        if (!self::isPropertyToken($token)) {
            return false;
        }

        $propertyId = $token[1];
        return true;
    }

    private static function scanFunction($input, &$name, &$arguments)
    {
        if (!self::isFunctionToken($input)) {
            return false;
        }

        $name = $input[1];
        $arguments = array_slice($input, 2);
        return true;
    }

    private static function scanObject($input, &$object)
    {
        if (($input === null) || ($input[0] !== Parser::TYPE_OBJECT)) {
            return false;
        }

        $object = $input[1];
        return true;
    }

    private static function isParameterToken($token)
    {
        return is_array($token) && ($token[0] === Parser::TYPE_PARAMETER);
    }

    private static function isPropertyToken($token)
    {
        return is_array($token) && ($token[0] === Parser::TYPE_VALUE);
    }

    private static function isFunctionToken($token)
    {
        return is_array($token) && ($token[0] === Parser::TYPE_FUNCTION);
    }
}
