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

    public function testFilterByName()
    {
        $scenario = self::getPeopleScenario();

        $method = 'people.filter((name = :name0) or (name = :name1)).map(id)';

        $arguments = array(
            'name0' => 'Ann',
            'name1' => 'Becca'
        );

        $mysql = <<<'EOS'
SELECT
    `0`.`Id` AS `0`
    FROM `People` AS `0`
    WHERE ((`0`.`Name` <=> :0) OR (`0`.`Name` <=> :1))
EOS;

        $phpInput = <<<'EOS'
$output = array(
    $input['name0'],
    $input['name1']
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
            "Friends": ["`Friends`", "`0`.`Friend` <=> `1`.`Person`", "`Person`", true, true]
        }
    }
}
EOS;
    }

    public function testStringComparisons()
    {
        $scenario = self::getPeopleScenario();

        $method = 'people.filter(' .
            'name < :otherName or ' .
            'name > :otherName or ' .
            'name <= :otherName or ' .
            'name >= :otherName and ' .
            'name != :otherName' .
        ').map(id)';

        $arguments = array(
            'otherName' => 'cesium'
        );

        $mysql = 'SELECT ' .
            '`0`.`Id` AS `0` ' .
            'FROM `People` AS `0` ' .
            'WHERE (' .
            '(((`0`.`Name` < :0) OR ' .
            '(`0`.`Name` > :0)) OR ' .
            '(`0`.`Name` <= :0)) OR ' .
            '((`0`.`Name` >= :0) AND ' .
            '(NOT (`0`.`Name` <=> :0))' .
        '))';

        $phpInput = <<<'EOS'
$output = array(
    $input['otherName']
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

    
    public function testMapDepthZero()
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

    public function testMapDepthOne()
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

    public function testMapDepthTwo()
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

    private static function getRelationshipsScenario()
    {
        /*
        DROP DATABASE IF EXISTS `database`;
        CREATE DATABASE `database`;
        USE `database`;

        CREATE TABLE `Names` (
            `Id` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            `First` VARCHAR(256) NOT NULL,
            `Last` VARCHAR(256) NOT NULL
        );

        CREATE TABLE `PhoneNumbers` (
            `Person` INT UNSIGNED NOT NULL,
            `PhoneNumber` BIGINT UNSIGNED NOT NULL,
            INDEX (`Person`)
        );

        CREATE TABLE `People` (
            `Id` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            `Name` INT UNSIGNED NOT NULL,
            `Age` TINYINT UNSIGNED NOT NULL,
            `City` VARCHAR(256) NOT NULL,
            CONSTRAINT `fk_People_Name__Names_Id` FOREIGN KEY (`Name`) REFERENCES `Names` (`Id`),
            CONSTRAINT `fk_People_Id__PhoneNumbers_Person` FOREIGN KEY (`Id`) REFERENCES `PhoneNumbers` (`Person`)
        );

        CREATE TABLE `Spouses` (
            `Person` INT UNSIGNED NOT NULL,
            `Spouse` INT UNSIGNED NOT NULL,
            CONSTRAINT `uc_Spouses_Person` UNIQUE (`Person`),
            CONSTRAINT `fk_Spouses_Spouse__People_Id` FOREIGN KEY (`Spouse`) REFERENCES `People` (`Id`)
        );

        CREATE TABLE `Friends` (
            `Person` INT UNSIGNED NOT NULL,
            `Friend` INT UNSIGNED NOT NULL
        );

        INSERT INTO `Names`
            (`Id`, `First`, `Last`) VALUES
            (1, 'Ann', 'Adams', 'San Francisco'),
            (2, 'Bob', 'Baker', 'Boston'),
            (3, 'Carl', 'Clay', 'Baltimore'),
            (4, 'Mary', 'May', 'San Antonio');

        INSERT INTO `PhoneNumbers`
            (`Person`, `PhoneNumber`) VALUES
            (1, 12025550164),
            (1, 12025550182),
            (2, 12025550110),
            (3, 12025550194),
            (4, 12025550180);

        INSERT INTO `People`
            (`Id`, `Name`, `Age`, `City`) VALUES
            (1, 1, 21),
            (2, 2, 28),
            (3, 3, 18),
            (4, 4, 26);

        INSERT INTO `Spouses`
            (`Person`, `Spouse`) VALUES
            (2, 4),
            (4, 2);

        INSERT INTO `Friends`
            (`Person`, `Friend`) VALUES
            (1, 2),
            (1, 3),
            (3, 1);
        */

        return <<<'EOS'
{
    "classes": {
        "Database": {
            "people": ["Person", "People"]
        },
        "Person": {
            "name": ["Name", "Name"],
            "city": [4, "City"],
            "age": [2, "Age"],
            "phones": [2, "Phones", "Number"],
            "spouse": ["Person", "Spouse", "Person"],
            "friends": ["Friend", "Friends"]
        },
        "Name": {
            "first": [4, "First"],
            "last": [4, "Last"]
        },
        "Friend": {
            "id": [2, "Id"]
        }
    },
    "values": {
        "`People`": {
            "Age": ["`Age`", false],
            "City": ["`City`", false]
        },
        "`Names`": {
            "First": ["`First`", false],
            "Last": ["`Last`", false]
        },
        "`PhoneNumbers`": {
            "Number": ["`PhoneNumber`", false]
        },
        "`Friends`": {
            "Id": ["`Friend`", false]
        }
    },
    "lists": {
        "People": ["`People`", "`Id`", false]
    },
    "connections": {
        "`People`": {
            "Name": ["`Names`", "`0`.`Name` <=> `1`.`Id`", "`Id`", false, false],
            "Phones": ["`PhoneNumbers`", "`0`.`Id` <=> `1`.`Person`", "`Person`", false, true],
            "Spouse": ["`Spouses`", "`0`.`Id` <=> `1`.`Person`", "`Person`", true, false],
            "Friends": ["`Friends`", "`0`.`Id` <=> `1`.`Person`", "`Person`", true, true]
        },
        "`Spouses`": {
            "Person": ["`People`", "`0`.`Spouse` <=> `1`.`Id`", "`Id`", true, true]
        }
    }
}
EOS;
    }
    
    public function testMatchString()
    {
        $scenario = self::getRelationshipsScenario();

        $method = <<<'EOS'
people.filter(match(city, :regex)).map(age)
EOS;

        $arguments = array(
            'regex' => '^'
        );

        $mysql = <<<'EOS'
SELECT
    `0`.`Id` AS `0`,
    `0`.`Age` AS `1`
    FROM `People` AS `0`
    WHERE (`0`.`City` REGEXP BINARY :0)
EOS;

        $phpInput = <<<'EOS'
$output = array(
    $input['regex']
);
EOS;

        $phpOutput = <<<'EOS'
foreach ($input as $row) {
    $output[$row[0]] = (integer)$row[1];
}

$output = isset($output) ? array_values($output) : array();
EOS;

        $this->verify($scenario, $method, $arguments, $mysql, $phpInput, $phpOutput);
    }
    
    public function testMatchPropertyPath()
    {
        $scenario = self::getRelationshipsScenario();

        $method = <<<'EOS'
people.filter(match(name.first, :regex)).map(age)
EOS;

        $arguments = array(
            'regex' => '^'
        );

        $mysql = <<<'EOS'
SELECT
    `0`.`Id` AS `0`,
    `0`.`Age` AS `1`
    FROM `People` AS `0`
    INNER JOIN `Names` AS `1` ON `0`.`Name` <=> `1`.`Id`
    WHERE (`1`.`First` REGEXP BINARY :0)
EOS;
$phpInput = <<<'EOS'
$output = array(
    $input['regex']
);
EOS;

        $phpOutput = <<<'EOS'
foreach ($input as $row) {
    $output[$row[0]] = (integer)$row[1];
}

$output = isset($output) ? array_values($output) : array();
EOS;

        $this->verify($scenario, $method, $arguments, $mysql, $phpInput, $phpOutput);
    }
    
    public function testFailParamPath()
    {
        $scenario = self::getRelationshipsScenario();

        $method = <<<'EOS'
people.filter(match(name.:a, :regex)).map(age)
EOS;

        $arguments = array(
            'regex' => '^',
            'a' => 'foo'
        );

        $mysql = null;
        $phpInput = null;
        $phpOutput = null;

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
        
        // strip nonessential mysql whitespace
        $this->assertSame(
            TestUtils::removeMySQLWhitespace($expected[0]),
            TestUtils::removeMySQLWhitespace($actual[0])
        );
        
        // strip nonessential php whitespace
        for ($i = 1; $i <= 2; $i++) {
            $this->assertSame(
                TestUtils::removePHPWhitespace($expected[$i]),
                TestUtils::removePHPWhitespace($actual[$i])
            );
        }
    }
}
