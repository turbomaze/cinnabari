<?php

namespace Datto\Cinnabari;

require 'Lexer.php';
require 'Parser.php';
require 'Compiler.php';
require 'StatelessCompiler.php';
require 'Exception.php';
require 'Mysql/Select.php';
require 'Mysql/Expression/AbstractExpression.php';
require 'Mysql/Expression/AbstractOperatorBinary.php';
require 'Mysql/Expression/Column.php';
require 'Mysql/Expression/OperatorEqual.php';
require 'Mysql/Expression/OperatorLess.php';
require 'Mysql/Expression/OperatorOr.php';
require 'Mysql/Expression/Parameter.php';
require 'Format/Arguments.php';
require 'Php/Output.php';
require 'Schema.php';

$scenarioJson = <<<'EOS'
{
    "classes": {
        "Database": {
            "people": ["Person", "People"],
            "devices": ["Device", "Devices"]
        },

        "Person": {
            "id": [2, "Id"],
            "isMarried": [1, "Married"],
            "age": [2, "Age"],
            "height": [3, "Height"],
            "name": [4, "Name"],
            "email": [4, "Email"]
        },

        "Device": {
            "id": [2, "Id"],
            "type": [4, "Type"],
            "version": [2, "Version"]
        }
    },

    "values": {
        "`People`": {
            "Id": ["`Id`", false],
            "Married": ["`Married`", true],
            "Age": ["`Age`", true],
            "Height": ["`Height`", true],
            "Name": ["`Name`", true],
            "Email": ["IF(`Email` <=> '', NULL, LOWER(`Email`))", true]
        },

        "`Devices`": {
            "Id": ["`Id`", false],
            "type": ["`Type`", false],
            "version": ["`Version`", true]
        }
    },
    "lists": {
        "People": ["`People`", "`Id`", false],
        "Devices": ["`Devices`", "`Id`", false]
    }
}
EOS;

$scenario = json_decode($scenarioJson, true);
$lexer = new Lexer();
$parser = new Parser();


echo "--- --- --- --- ---   Api string    --- --- --- --- ---\n";
$query = "people.filter(name = :name0 and (id = :id0 or name = :name1)).map(id)";
$arguments = array('name0' => 'Ann', 'id0' => 3, 'name1' => 'Becca');
echo $query . "\n\n";
$tokens = $lexer->tokenize($query);
$request = $parser->parse($tokens);

echo "--- --- --- --- --- --- Request --- --- --- --- --- ---\n";
echo json_encode($request) . "\n\n";

echo "--- --- --- --- --- Compiled output --- --- --- --- ---\n";
$out = StatelessCompiler::compile($scenario, $request, $arguments);
echo $out[0] . "\n\n";
echo $out[1] . "\n\n";
echo $out[2] . "\n\n";
