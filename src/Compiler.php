<?php

namespace Datto\Cinnabari;

use Datto\Cinnabari\Grammar;
use Datto\Cinnabari\Mysql\Select;
use Datto\Cinnabari\Mysql\Expression\Column;
use Datto\Cinnabari\Mysql\Expression\Parameter;
use Datto\Cinnabari\Mysql\Expression\OperatorLess;
use Datto\Cinnabari\Mysql\Expression\OperatorPlus;
use Datto\Cinnabari\Format\Arguments;
use Datto\Cinnabari\Php\Output;

echo "here too \n";

class Compiler extends Grammar
{
    public function __construct()
    {
        parent::__construct(array( 
            'request' => 'filter',
            'filter' => '_isFilter',
            'lessFunc' => '_isLessFunc',
            'numFunc' => '_isPlusFunc',
            'numArg' => 'numProp | numParam | numFunc',
            'numProp' => '_isNumProp',
            'numParam' => '_isNumParam'
        ));
    }

    // not used currently; used in an alternate compilation method
    public function getDFSOrder($tree)
    {
        if (is_int($tree)) {
           return array(); 
        } elseif (!is_array($tree)) {
            return array($tree);
        } elseif ($tree[0] === Parser::TYPE_VALUE) {
            return array($tree);
        } elseif ($tree[0] === Parser::TYPE_PARAMETER) {
            return array($tree);
        } elseif ($tree[0] === Parser::TYPE_OBJECT) {
            return array($tree);
        }

        $flattened = array();
        foreach ($tree as $key => $kid) {
            $flattened = array_merge($flattened, $this->getDFSOrder($kid));
        }
        return $flattened;
    }

    private function initializeMySQL($preamble)
    {
        $state = $this->getState();
        $symbols = $state['symbols'];
        $mysql = $state['mysql'];

        // the table entry point
        $mysql->setTable($preamble[0][1]);
        $idReference = $symbols[$preamble[0][2]];
        $mysql->addValue($idReference);

        // the joins
        foreach (array_slice($preamble, 1) as $token) {
            $joinToken = $token[0];
            $joinId= $token[1];
            $join = $symbols[$joinId];
            $mysql->insertIntoTables($join);
        }
    }

    public function compile($symbols, $preamble, $annotatedTree, $arguments)
    {
        $this->setState(array(
            'symbols' => $symbols,
            'tree' => $annotatedTree,
            'arguments' => $arguments,

            'mysql' => new Select(),
            'formatInput' => new Arguments($arguments),
            'phpOutput' => null
        ));

        $this->initializeMySQL($preamble);

        $this->applyRule('filter');

        $state = $this->getState();

        return array(
            $state['mysql']->getMysql(),
            $state['formatInput']->getPhp(),
            $state['phpOutput']
        );
    }

    public function isFilter()
    {
        $state = $this->getState();
        $token = $state['tree'][0];
        list($type, $name, $argument) = $token;

        if ($type !== Parser::TYPE_FUNCTION || $name !== 'filter') {
            return false;
        }
        
        $state['tree'] = array($argument);
        $this->setState($state);

        if ($this->applyRule('lessFunc')) {
            $updatedState = $this->getState();
            $updatedState['tree'] = array_slice($updatedState['tree'], 1);

            $updatedState['mysql']->setWhere($updatedState['expression'][0]);

            echo $updatedState['expression'][0]->getMysql() . "\n\n";

            $this->setState($updatedState);
            return true;
        }
        return false;    
    }

    public function isLessFunc()
    {
        $state = $this->getState();
        $token = $state['tree'][0];
        if (count($token) < 2) return false;
        list($type, $name) = $token;
        if ($type !== Parser::TYPE_FUNCTION || $name !== 'less') {
            return false;
        }
        $arguments = array_slice($token, 2);
        
        $state['tree'] = $arguments;
        $this->setState($state);

        if ($this->applyRule('numArg') && $this->applyRule('numArg')) {
            $updatedState = $this->getState();

            $expressionA = $updatedState['expression'][0];
            $expressionB = $updatedState['expression'][1];

            $updatedState['tree'] = array_slice($state['tree'], 1);
            $updatedState['expression'] = array(new OperatorLess($expressionA, $expressionB));

            $this->setState($updatedState);
            return true;
        }
        return false;    
    }

    public function isNumArg($propOrParam)
    {
        $state = $this->getState();

        if (count($state['tree']) < 1) return false;

        $token = $state['tree'][0];
        if ($token[0] === $propOrParam) {
            $state['tree'] = array_slice($state['tree'], 1);

            if (!isset($state['expression'])) {
                $state['expression'] = array();
            }

            if ($propOrParam === Parser::TYPE_VALUE) {
                list($columnReference, $dataType, $isNullable) = $state['symbols'][$token[1]];
                $column = new Column($columnReference, $isNullable, $dataType);
                $state['mysql']->addValue($columnReference);
                $state['expression'][] = $column;
            } else { // it's a parameter
                list($name, $neededType) = $state['symbols'][$token[1]];
                $id = $state['formatInput']->useArgument($name, $neededType);
                $parameter = new Parameter($id);
                $state['expression'][] = $parameter;
            }

            $this->setState($state);
            return true;
        }
        return false;    
    }

    public function isNumProp()
    {
        return $this->isNumArg(Parser::TYPE_VALUE);
    }

    public function isNumParam()
    {
        return $this->isNumArg(Parser::TYPE_PARAMETER);
    } 

    public function isPlusFunc()
    {
        $state = $this->getState();
        $token = $state['tree'];
        if (count($token) < 2) return false;
        list($type, $name) = $token;

        if ($type !== Parser::TYPE_FUNCTION || $name !== 'plus') {
            return false;
        }

        $arguments = array_slice($token, 2);
        
        $state['tree'] = $argument;
        $this->setState($state);

        if ($this->applyRule('numArg') && $this->applyRule('numArg')) {
            $state['tree'] = array_slice($state['tree'], 1);

            $expressionA = $state['expression'][0];
            $expressionB = $state['expression'][1];

            $state['expression'] = array(new OperatorPlus($expressionA, $expressionB));

            $this->setState($state);
            return true;
        }
        return false;    
    }
    
    protected function getState() 
    {
        return $this->_state;
    }

    protected function setState($state) 
    {
        $this->_state = $state;
    }
}

function log($msg) {
    echo "DEBUG :: " . json_encode($msg) . "\n\n";
}
