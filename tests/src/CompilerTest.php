<?php

namespace Datto\Cinnabari\Tests;

use Datto\Cinnabari\Compiler;
use Datto\Cinnabari\Lexer;
use Datto\Cinnabari\Parser;
use Datto\Cinnabari\Schema;
use PHPUnit_Framework_TestCase;

/*
When joining from an origin table to a destination table:
 * Assume there is exactly one matching row in the destination table
 * If there is NO foreign key:
      Add the possibility of no matching rows in the destination table
 * If there is either (a) NO uniqueness constraint on the destination table, or (b) BOTH the origin and destination columns are nullable:
      Add the possibility of many matching rows
*/

class CompilerTest extends PHPUnit_Framework_TestCase
{
    private static function getPeopleScenario()
    {
        /*
        DROP DATABASE IF EXISTS `database`;
        CREATE DATABASE `database`;
        USE `database`;

        CREATE TABLE `People` (
            `Id` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            `Married` TINYINT UNSIGNED,
            `Age` TINYINT UNSIGNED,
            `Height` FLOAT,
            `Name` VARCHAR(256),
            `Email` VARCHAR(256)
        );

        INSERT INTO `People`
            (`Id`, `Married`, `Age`, `Height`, `Name`, `Email`) VALUES
            (1, 1, 21, 5.75, "Ann", "Ann@Example.Com"),
            (2, 0, 18, 5.5, "Becca", "becca@example.com"),
            (3, 1, 36, 5.9, "Carl", "carl@example.com"),
            (4, 0, 9, 4.25, "Dan", ""),
            (5, null, null, null, null, null);
        */

        return <<<'EOS'
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
    }

    public function testMapValue()
    {
        $scenario = self::getPeopleScenario();

        $method = <<<'EOS'
people.map(id)
EOS;

        $arguments = array();

        $mysql = <<<'EOS'
SELECT
    `0`.`Id` AS `0`
    FROM `People` AS `0`
EOS;

        $phpInput = <<<'EOS'
$output = array();
EOS;

        $phpOutput = <<<'EOS'
foreach ($input as $row) {
    $output[$row[0]] = (integer)$row[0];
}

$output = isset($output) ? array_values($output) : array();
EOS;

        $this->verify($scenario, $method, $arguments, $mysql, $phpInput, $phpOutput);
    }

    public function testMapBasicObject()
    {
        $scenario = self::getPeopleScenario();

        $method = <<<'EOS'
people.map({
    "id": id,
    "married": isMarried,
    "age": age,
    "height": height,
    "name": name
})
EOS;

        $arguments = array();

        $mysql = <<<'EOS'
SELECT
    `0`.`Id` AS `0`,
    `0`.`Married` AS `1`,
    `0`.`Age` AS `2`,
    `0`.`Height` AS `3`,
    `0`.`Name` AS `4`
    FROM `People` AS `0`
EOS;

        $phpInput = <<<'EOS'
$output = array();
EOS;

        $phpOutput = <<<'EOS'
foreach ($input as $row) {
    $output[$row[0]]['id'] = (integer)$row[0];
    $output[$row[0]]['married'] = isset($row[1]) ? (boolean)$row[1] : null;
    $output[$row[0]]['age'] = isset($row[2]) ? (integer)$row[2] : null;
    $output[$row[0]]['height'] = isset($row[3]) ? (float)$row[3] : null;
    $output[$row[0]]['name'] = $row[4];
}

$output = isset($output) ? array_values($output) : array();
EOS;

        $this->verify($scenario, $method, $arguments, $mysql, $phpInput, $phpOutput);
    }

    public function testMapAdvancedObject()
    {
        $scenario = self::getPeopleScenario();

        $method = <<<'EOS'
people.map({
    "name": name,
    "contact": {
        "email": email
    }
})
EOS;

        $arguments = array();

        $mysql = <<<'EOS'
SELECT
    `0`.`Id` AS `0`,
    `0`.`Name` AS `1`,
    IF(`0`.`Email` <=> '', NULL, LOWER(`0`.`Email`)) AS `2`
    FROM `People` AS `0`
EOS;

        $phpInput = <<<'EOS'
$output = array();
EOS;

        $phpOutput = <<<'EOS'
foreach ($input as $row) {
    $output[$row[0]]['name'] = $row[1];
    $output[$row[0]]['contact']['email'] = $row[2];
}

$output = isset($output) ? array_values($output) : array();
EOS;

        $this->verify($scenario, $method, $arguments, $mysql, $phpInput, $phpOutput);
    }

    public function testFilter()
    {
        $scenario = self::getPeopleScenario();

        $method = 'people.filter((age = :missingAge) or (age < :minimumAge)).map(id)';

        $arguments = array(
            'missingAge' => null,
            'minimumAge' => 21
        );

        $mysql = <<<'EOS'
SELECT
    `0`.`Id` AS `0`
    FROM `People` AS `0`
    WHERE ((`0`.`Age` <=> :0) OR (`0`.`Age` < :1))
EOS;

        $phpInput = <<<'EOS'
$output = array(
    $input['missingAge'],
    $input['minimumAge']
);
EOS;

        $phpOutput = <<<'EOS'
foreach ($input as $row) {
    $output[$row[0]] = (integer)$row[0];
}

$output = isset($output) ? array_values($output) : array();
EOS;

        $this->verify($scenario, $method, $arguments, $mysql, $phpInput, $phpOutput);
    }

    private static function getFriendsScenario()
    {
        /*
        DROP DATABASE IF EXISTS `database`;
        CREATE DATABASE `database`;
        USE `database`;

        CREATE TABLE `Friends` (
            `Person` INT UNSIGNED,
            `Friend` INT UNSIGNED
        );

        INSERT INTO `Friends`
            (`Person`, `Friend`) VALUES
            (0, 1),
            (1, 0),
            (1, 2),
            (2, null),
            (null, null);
        */

        return <<<'EOS'
{
    "classes": {
        "Database": {
            "people": ["Person", "Friends"]
        },
        "Person": {
            "id": [2, "Person"],
            "friends": ["Person", "Friends"]
        }
    },
    "values": {
        "`Friends`": {
            "Person": ["`Person`", true]
        }
    },
    "lists": {
        "Friends": ["`Friends`", "`Person`", true]
    },
    "connections": {
        "`Friends`": {
            "Friends": ["`Friends`", "`0`.`Friend` <=> `1`.`Person`", true, true]
        }
    }
}
EOS;
    }

    public function testMapOffIndex()
    {
        $scenario = self::getFriendsScenario();

        $method = <<<'EOS'
people.map(id)
EOS;

        $arguments = array();

        $mysql = <<<'EOS'
SELECT
    `0`.`Person` AS `0`
    FROM `Friends` AS `0`
EOS;

        $phpInput = <<<'EOS'
$output = array();
EOS;

        $phpOutput = <<<'EOS'
foreach ($input as $row) {
    if (isset($row[0])) {
        $output[$row[0]] = isset($row[0]) ? (integer)$row[0] : null;
    }
}

$output = isset($output) ? array_values($output) : array();
EOS;

        $this->verify($scenario, $method, $arguments, $mysql, $phpInput, $phpOutput);
    }

    public function testMapOffIndexDepthOne()
    {
        $scenario = self::getFriendsScenario();

        $method = <<<'EOS'
people.map({
    "id": id,
    "friends": friends.map(id)
})
EOS;

        $arguments = array();

        $mysql = <<<'EOS'
SELECT
    `0`.`Person` AS `0`,
    `1`.`Person` AS `1`
    FROM `Friends` AS `0`
    LEFT JOIN `Friends` AS `1` ON `0`.`Friend` <=> `1`.`Person`
EOS;

        $phpInput = <<<'EOS'
$output = array();
EOS;

        $phpOutput = <<<'EOS'
foreach ($input as $row) {
    if (isset($row[0])) {
        $output[$row[0]]['id'] = isset($row[0]) ? (integer)$row[0] : null;

        if (isset($row[1])) {
            $output[$row[0]]['friends'][$row[1]] = isset($row[1]) ? (integer)$row[1] : null;
        }
    }
}

$output = isset($output) ? array_values($output) : array();

foreach ($output as &$x0) {
    $x0['friends'] = isset($x0['friends']) ? array_values($x0['friends']) : array();
}
EOS;

        $this->verify($scenario, $method, $arguments, $mysql, $phpInput, $phpOutput);
    }

    public function testMapOffIndexDepthTwo()
    {
        $scenario = self::getFriendsScenario();

        $method = <<<'EOS'
people.map({
    "id": id,
    "friends": friends.map({
        "id": id,
        "friends": friends.map(id)
    })
})
EOS;

        $arguments = array();

        $mysql = <<<'EOS'
SELECT
    `0`.`Person` AS `0`,
    `1`.`Person` AS `1`,
    `2`.`Person` AS `2`
    FROM `Friends` AS `0`
    LEFT JOIN `Friends` AS `1` ON `0`.`Friend` <=> `1`.`Person`
    LEFT JOIN `Friends` AS `2` ON `1`.`Friend` <=> `2`.`Person`
EOS;

        $phpInput = <<<'EOS'
$output = array();
EOS;

        $phpOutput = <<<'EOS'
foreach ($input as $row) {
    if (isset($row[0])) {
        $output[$row[0]]['id'] = isset($row[0]) ? (integer)$row[0] : null;

        if (isset($row[1])) {
            $output[$row[0]]['friends'][$row[1]]['id'] = isset($row[1]) ? (integer)$row[1] : null;

            if (isset($row[2])) {
                $output[$row[0]]['friends'][$row[1]]['friends'][$row[2]] = isset($row[2]) ? (integer)$row[2] : null;
            }
        }
    }
}

$output = isset($output) ? array_values($output) : array();

foreach ($output as &$x1) {
    $x1['friends'] = isset($x1['friends']) ? array_values($x1['friends']) : array();

    foreach ($x1['friends'] as &$x0) {
        $x0['friends'] = isset($x0['friends']) ? array_values($x0['friends']) : array();
    }
}
EOS;

        $this->verify($scenario, $method, $arguments, $mysql, $phpInput, $phpOutput);
    }

    private function verify($scenarioJson, $method, $arguments, $mysql, $phpInput, $phpOutput)
    {
        $scenario = json_decode($scenarioJson, true);

        $lexer = new Lexer();
        $parser = new Parser();
        $schema = new Schema($scenario);
        $compiler = new Compiler($schema);

        $tokens = $lexer->tokenize($method);
        $request = $parser->parse($tokens);
        $actual = $compiler->compile($request, $arguments);

        $expected = array($mysql, $phpInput, $phpOutput);
        $this->assertSame($expected, $actual);
    }
}
