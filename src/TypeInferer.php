<?php

namespace Datto\Cinnabari;

use Datto\Cinnabari\Php\Output;

class TypeInferer
{
    const ANY_TYPE = null;

    private $symbols; // the symbol table
    private $tree; // the tree to process

    private static $TYPE_MAP = array(
        'plus' => array(
            array('numeric'),
            array('numeric')
        )
    );

    public function infer($symbolTable, $tree)
    {
        $this->symbols = $symbolTable;
        $this->tree = $tree;

        $lastSeenType = 'integer';

        // hacky way of getting it to do "well enough"
        foreach ($this->symbols as $key => $symbol) {
            if (strpos($symbol[0], ':') === 0) {
                $this->symbols[$key] = array(substr($symbol[0], 1), $lastSeenType);
            } else if (count($symbol) === 3) {
                $mapping = array(
                    Output::TYPE_NULL => 'null', 
                    Output::TYPE_INTEGER => 'integer', 
                    Output::TYPE_FLOAT => 'float', 
                    Output::TYPE_BOOLEAN => 'boolean', 
                    Output::TYPE_STRING => 'string'
                );
                $lastSeenType = $mapping[$symbol[1]];
            }
        }

        $pseudocode = <<<'EOS'
foreach top level function token {
    get that function's required types

    iterate through the combos

    apply the type info to the kids and have them actually set their types to that type

    if they can't match that type, return false

    when one turns true, stop, because the current state is valid
}
EOS;


        return array($this->symbols, $this->tree);
    }

    public function getFunction($function)
    {
        return false;
    }
}
