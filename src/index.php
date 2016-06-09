<?php

namespace Datto\Cinnabari;

require 'Lexer.php';
require 'Parser.php';
require 'Compiler.php';
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
            "people": ["Person", "People"]
        },
        "Person": {
            "id": [2, "Id"],
            "isMarried": [1, "Married"],
            "age": [2, "Age"],
            "height": [3, "Height"],
            "name": [4, "Name"],
            "email": [4, "Email"]
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
        }
    },
    "lists": {
        "People": ["`People`", "`Id`", false]
    }
}
EOS;

$scenario = json_decode($scenarioJson, true);
$schema = new Schema($scenario);
$lexer = new Lexer();
$parser = new Parser();
$compiler = new Compiler($schema);

$query = 'people.map(id)';
$arguments = array();

$tokens = $lexer->tokenize($query);
$request = $parser->parse($tokens);
$out = $compiler->compile($request, $arguments);

echo "--- --- --- --- --- ---   Api string    --- --- --- --- --- ---\n";
echo $query . "\n\n\n";

echo "--- --- --- --- --- --- --- Tokens  --- --- --- --- --- --- ---\n";
echo json_encode($tokens) . "\n\n\n";

echo "--- --- --- --- --- --- --- Request --- --- --- --- --- --- ---\n";
echo json_encode($request) . "\n\n\n";

echo "--- --- --- --- --- --- Compiled output --- --- --- --- --- ---\n";

echo $out[0] . "\n\n";
echo $out[1] . "\n\n";
echo $out[2] . "\n\n";
