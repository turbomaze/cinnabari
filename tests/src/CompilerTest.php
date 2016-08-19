<?php

namespace Datto\Cinnabari\Tests;

use Datto\Cinnabari\Cinnabari;
use Datto\Cinnabari\Exception\CinnabariException;
use PHPUnit_Framework_TestCase;

/*
When joining from an origin table to a destination table:
 * Assume there is exactly one matching row in the destination table
 * If there is NO foreign key:
      Add the possibility of no matching rows in the destination table
 * If there is either:
     (a) NO uniqueness constraint on the destination table, or
     (b) BOTH the origin and destination columns are nullable:
 * Then add the possibility of many matching rows
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
            (1, 'Ann', 'Adams'),
            (2, 'Bob', 'Baker'),
            (3, 'Carl', 'Clay'),
            (4, 'Mary', 'May');

        INSERT INTO `PhoneNumbers`
            (`Person`, `PhoneNumber`) VALUES
            (1, 12025550164),
            (1, 12025550182),
            (2, 12025550110),
            (3, 12025550194),
            (4, 12025550180);

        INSERT INTO `People`
            (`Id`, `Name`, `Age`) VALUES
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
            "Age": ["`Age`", false]
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

    public function testGetValue()
    {
        $scenario = self::getPeopleScenario();

        $method = <<<'EOS'
get(
    people,
    id
)
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

        $this->verifyResult($scenario, $method, $arguments, $mysql, $phpInput,
            $phpOutput);
    }

    public function testGetBasicObject()
    {
        $scenario = self::getPeopleScenario();

        $method = <<<'EOS'
get(
    people,
    {
        "id": id,
        "married": isMarried,
        "age": age,
        "height": height,
        "name": name
    }
)
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

        $this->verifyResult($scenario, $method, $arguments, $mysql, $phpInput,
            $phpOutput);
    }

    public function testGetAdvancedObject()
    {
        $scenario = self::getPeopleScenario();

        $method = <<<'EOS'
get(
    people,
    {
        "name": name,
        "contact": {
            "email": email
        }
    }
)
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

        $this->verifyResult($scenario, $method, $arguments, $mysql, $phpInput,
            $phpOutput);
    }

    public function testGetFilter()
    {
        $scenario = self::getPeopleScenario();

        $method = <<<'EOS'
get(
    filter(people, age = :0),
    id
)
EOS;

        $arguments = array(
            21
        );

        $mysql = <<<'EOS'
SELECT
    `0`.`Id` AS `0`
    FROM `People` AS `0`
    WHERE (`0`.`Age` <=> :0)
EOS;

        $phpInput = <<<'EOS'
if (is_integer($input[0])) {
    $output = array(
        $input['0']
    );
} else {
    $output = null;
}
EOS;

        $phpOutput = <<<'EOS'
foreach ($input as $row) {
    $output[$row[0]] = (integer)$row[0];
}

$output = isset($output) ? array_values($output) : array();
EOS;

        $this->verifyResult($scenario, $method, $arguments, $mysql, $phpInput,
            $phpOutput);
    }

    public function testGetAdvancedFilter()
    {
        $scenario = self::getPeopleScenario();

        $method = <<<'EOS'
get(
    filter(
        people,
        age = :null
        or (not :true or :ageA < age)
        and age <= :ageB
        and age != :ageC
        and age <= :ageD
        and age < :ageE
    ),
    id
)
EOS;

        $arguments = array(
            'null' => null,
            'true' => true,
            'ageA' => 20,
            'ageB' => 21,
            'ageC' => 22,
            'ageD' => 23,
            'ageE' => 24
        );

        $mysql = <<<'EOS'
SELECT
    `0`.`Id` AS `0`
    FROM `People` AS `0`
    WHERE
        (
            (`0`.`Age` <=> :0)
            OR (
                (
                    (
                        (
                            (
                                (NOT :1) OR (:2 < `0`.`Age`)
                            ) AND (`0`.`Age` <= :3)
                        ) AND (NOT (`0`.`Age` <=> :4))
                    ) AND (`0`.`Age` <= :5)
                ) AND (`0`.`Age` < :6)
            )
        )
EOS;

        $phpInput = <<<'EOS'
if (
    is_integer($input['null']) && (
        is_bool($input['true']) && (
            (
                is_integer($input['ageA']) && (
                    (
                        is_integer($input['ageB']) && (
                            is_integer($input['ageC']) && (
                                (
                                    is_integer($input['ageD']) && (is_integer($input['ageE']) || is_float($input['ageE']))
                                ) || (
                                    is_float($input['ageD']) && (is_integer($input['ageE']) || is_float($input['ageE']))
                                )
                            )
                        )
                    ) || (
                        is_float($input['ageB']) && (
                            is_integer($input['ageC']) && (
                                (
                                    is_integer($input['ageD']) && (is_integer($input['ageE']) || is_float($input['ageE']))
                                ) || (
                                    is_float($input['ageD']) && (is_integer($input['ageE']) || is_float($input['ageE']))
                                )
                            )
                        )
                    )
                )
            ) || (
                is_float($input['ageA']) && (
                    (
                        is_integer($input['ageB']) && (
                            is_integer($input['ageC']) && (
                                (
                                    is_integer($input['ageD']) && (is_integer($input['ageE']) || is_float($input['ageE']))
                                ) || (
                                    is_float($input['ageD']) && (is_integer($input['ageE']) || is_float($input['ageE']))
                                )
                            )
                        )
                    ) || (
                        is_float($input['ageB']) && (
                            is_integer($input['ageC']) && (
                                (
                                    is_integer($input['ageD']) && (is_integer($input['ageE']) || is_float($input['ageE']))
                                ) || (
                                    is_float($input['ageD']) && (is_integer($input['ageE']) || is_float($input['ageE']))
                                )
                            )
                        )
                    )
                )
            )
        )
    )
) {
    $output = array(
        $input['null'],
        $input['true'],
        $input['ageA'],
        $input['ageB'],
        $input['ageC'],
        $input['ageD'],
        $input['ageE']
    );
} else {
    $output = null;
}
EOS;

        $phpOutput = <<<'EOS'
foreach ($input as $row) {
    $output[$row[0]] = (integer)$row[0];
}

$output = isset($output) ? array_values($output) : array();
EOS;

        $this->verifyResult($scenario, $method, $arguments, $mysql, $phpInput,
            $phpOutput);
    }

    public function getSort()
    {
        $scenario = self::getPeopleScenario();

        $method = <<<'EOS'
get(
    sort(people, age),
    id
)
EOS;

        $arguments = array();

        $mysql = <<<'EOS'
SELECT
    `0`.`Id` AS `0`
    FROM `People` AS `0`
    ORDER BY `0`.`Age` ASC
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

        $this->verifyResult($scenario, $method, $arguments, $mysql, $phpInput,
            $phpOutput);
    }

    public function testGetAdvancedSort()
    {
        $scenario = self::getRelationshipsScenario();

        $method = <<<'EOS'
get(
    sort(people, name.first),
    age
)
EOS;

        $arguments = array();

        $mysql = <<<'EOS'
SELECT
    `0`.`Id` AS `0`,
    `0`.`Age` AS `1`
    FROM `People` AS `0`
    INNER JOIN `Names` AS `1` ON `0`.`Name` <=> `1`.`Id`
    ORDER BY `1`.`First` ASC
EOS;

        $phpInput = <<<'EOS'
$output = array();
EOS;

        $phpOutput = <<<'EOS'
foreach ($input as $row) {
    $output[$row[0]] = (integer)$row[1];
}

$output = isset($output) ? array_values($output) : array();
EOS;

        $this->verifyResult($scenario, $method, $arguments, $mysql, $phpInput,
            $phpOutput);
    }

    public function testGetSliceSort()
    {
        $scenario = self::getPeopleScenario();

        $method = <<<'EOS'
get(
    slice(sort(people, age), :start, :stop),
    id
)
EOS;

        $arguments = array(
            'start' => 0,
            'stop' => 10
        );

        $mysql = <<<'EOS'
SELECT
    `0`.`Id` AS `0`
    FROM `People` AS `0`
    ORDER BY `0`.`Age` ASC
    LIMIT :0, :1
EOS;

        $phpInput = <<<'EOS'
if (
    is_integer($input['stop']) && is_integer($input['start'])
) {
    $output = array(
        $input['start'],
        $input['stop'] - $input['start']
    );
} else {
    $output = null;
}
EOS;

        $phpOutput = <<<'EOS'
foreach ($input as $row) {
    $output[$row[0]] = (integer)$row[0];
}

$output = isset($output) ? array_values($output) : array();
EOS;

        $this->verifyResult($scenario, $method, $arguments, $mysql, $phpInput, $phpOutput);
    }

    public function testGet()
    {
        $scenario = self::getFriendsScenario();

        $method = <<<'EOS'
get(
    people,
    id
)
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

        $this->verifyResult($scenario, $method, $arguments, $mysql, $phpInput,
            $phpOutput);
    }

    public function testGetGet()
    {
        $scenario = self::getFriendsScenario();

        $method = <<<'EOS'
get(
    people,
    {
        "id": id,
        "friends": get(
            friends,
            id
        )
    }
)
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

        $this->verifyResult($scenario, $method, $arguments, $mysql, $phpInput,
            $phpOutput);
    }

    public function testGetGetGet()
    {
        $scenario = self::getFriendsScenario();

        $method = <<<'EOS'
get(
    people,
    {
        "id": id,
        "friends": get(
            friends,
            {
                "id": id,
                "friends": get(
                    friends,
                    id
                )
            }
        )
    }
)
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

        $this->verifyResult($scenario, $method, $arguments, $mysql, $phpInput,
            $phpOutput);
    }

    public function testGetFilterMatch()
    {
        $scenario = self::getRelationshipsScenario();

        $method = <<<'EOS'
get(
    filter(people, match(name.first, :firstName)),
    age
)
EOS;

        $arguments = array(
            'firstName' => '^[A-Z]a..$'
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
if (is_string($input['firstName'])) {
    $output = array(
        $input['firstName']
    );
} else {
    $output = null;
}
EOS;

        $phpOutput = <<<'EOS'
foreach ($input as $row) {
    $output[$row[0]] = (integer)$row[1];
}

$output = isset($output) ? array_values($output) : array();
EOS;

        $this->verifyResult($scenario, $method, $arguments, $mysql, $phpInput,
            $phpOutput);
    }

    public function testGetLowercase()
    {
        $scenario = self::getPeopleScenario();

        $method = <<<'EOS'
get(
    people,
    lowercase(name)
)
EOS;

        $arguments = array();

        $mysql = <<<'EOS'
SELECT
    `0`.`Id` AS `0`,
    LOWER(`0`.`Name`) AS `1`
    FROM `People` AS `0`
EOS;

        $phpInput = <<<'EOS'
$output = array();
EOS;

        $phpOutput = <<<'EOS'
foreach ($input as $row) {
    $output[$row[0]] = $row[1];
}

$output = isset($output) ? array_values($output) : array();
EOS;

        $this->verifyResult($scenario, $method, $arguments, $mysql, $phpInput,
            $phpOutput);
    }

    public function testGetUppercase()
    {
        $scenario = self::getPeopleScenario();

        $method = <<<'EOS'
get(
    people,
    uppercase(name)
)
EOS;

        $arguments = array();

        $mysql = <<<'EOS'
SELECT
    `0`.`Id` AS `0`,
    UPPER(`0`.`Name`) AS `1`
    FROM `People` AS `0`
EOS;

        $phpInput = <<<'EOS'
$output = array();
EOS;

        $phpOutput = <<<'EOS'
foreach ($input as $row) {
    $output[$row[0]] = $row[1];
}

$output = isset($output) ? array_values($output) : array();
EOS;

        $this->verifyResult($scenario, $method, $arguments, $mysql, $phpInput,
            $phpOutput);
    }

    public function testGetLength()
    {
        $scenario = self::getPeopleScenario();

        $method = <<<'EOS'
get(
    people,
    length(name)
)
EOS;

        $arguments = array();

        $mysql = <<<'EOS'
SELECT
    `0`.`Id` AS `0`,
    CHAR_LENGTH(`0`.`Name`) AS `1`
    FROM `People` AS `0`
EOS;

        $phpInput = <<<'EOS'
$output = array();
EOS;

        $phpOutput = <<<'EOS'
foreach ($input as $row) {
    $output[$row[0]] = isset($row[1]) ? (integer)$row[1] : null;
}

$output = isset($output) ? array_values($output) : array();
EOS;

        $this->verifyResult($scenario, $method, $arguments, $mysql, $phpInput,
            $phpOutput);
    }

    public function testGetPlus()
    {
        $scenario = self::getPeopleScenario();

        $method = <<<'EOS'
get(
    people,
    name + name
)
EOS;

        $arguments = array();

        $mysql = <<<'EOS'
SELECT
    `0`.`Id` AS `0`,
    CONCAT(`0`.`Name`, `0`.`Name`) AS `1`
    FROM `People` AS `0`
EOS;

        $phpInput = <<<'EOS'
$output = array();
EOS;

        $phpOutput = <<<'EOS'
foreach ($input as $row) {
    $output[$row[0]] = $row[1];
}

$output = isset($output) ? array_values($output) : array();
EOS;

        $this->verifyResult($scenario, $method, $arguments, $mysql, $phpInput,
            $phpOutput);
    }

    public function testGetSubstring()
    {
        $scenario = self::getPeopleScenario();

        $method = <<<'EOS'
get(
    people,
    substring(name, :start, :stop)
)
EOS;

        $arguments = array(
            'start' => 1,
            'stop' => 2
        );

        $mysql = <<<'EOS'
SELECT
    `0`.`Id` AS `0`,
    SUBSTRING(`0`.`Name` FROM :0 FOR :1) AS `1`
    FROM `People` AS `0`
EOS;

        $phpInput = <<<'EOS'
if (
    is_integer($input['stop']) && is_integer($input['start'])
) {
    $output = array(
        $input['start'] + 1,
        $input['stop'] - $input['start']
    );
} else {
    $output = null;
}
EOS;

        $phpOutput = <<<'EOS'
foreach ($input as $row) {
    $output[$row[0]] = $row[1];
}

$output = isset($output) ? array_values($output) : array();
EOS;

        $this->verifyResult($scenario, $method, $arguments, $mysql, $phpInput,
            $phpOutput);
    }

    public function testCount()
    {
        $scenario = self::getPeopleScenario();

        $method = <<<'EOS'
count(
    people
)
EOS;

        $arguments = array();

        $mysql = <<<'EOS'
SELECT
    COUNT(TRUE) AS `0`
    FROM `People` AS `0`
EOS;

        $phpInput = <<<'EOS'
$output = array();
EOS;

        $phpOutput = <<<'EOS'
foreach ($input as $row) {
    $output = (integer)$row[0];
}
EOS;

        $this->verifyResult($scenario, $method, $arguments, $mysql, $phpInput,
            $phpOutput);
    }

    public function testCountFilter()
    {
        $scenario = self::getPeopleScenario();

        $method = <<<'EOS'
count(
    filter(people, age < :minimumAge)
)
EOS;

        $arguments = array(
            'minimumAge' => 18
        );

        $mysql = <<<'EOS'
SELECT
    COUNT(TRUE) AS `0`
    FROM `People` AS `0`
    WHERE (`0`.`Age` < :0)
EOS;

        $phpInput = <<<'EOS'
if (is_integer($input['minimumAge']) || is_float($input['minimumAge'])) {
    $output = array(
        $input['minimumAge']
    );
} else {
    $output = null;
}
EOS;

        $phpOutput = <<<'EOS'
foreach ($input as $row) {
    $output = (integer)$row[0];
}
EOS;

        $this->verifyResult($scenario, $method, $arguments, $mysql, $phpInput,
            $phpOutput);
    }

    public function testCountSort()
    {
        $scenario = self::getPeopleScenario();

        $method = <<<'EOS'
count(
    sort(people, age)
)
EOS;

        $arguments = array();

        $mysql = <<<'EOS'
SELECT
    COUNT(TRUE) AS `0`
    FROM `People` AS `0`
EOS;

        $phpInput = <<<'EOS'
$output = array();
EOS;

        $phpOutput = <<<'EOS'
foreach ($input as $row) {
    $output = (integer)$row[0];
}
EOS;

        $this->verifyResult($scenario, $method, $arguments, $mysql, $phpInput,
            $phpOutput);
    }

    public function testCountSlice()
    {
        $scenario = self::getPeopleScenario();

        $method = <<<'EOS'
count(
    slice(people, :start, :stop)
)
EOS;

        $arguments = array(
            'start' => 0,
            'stop' => 3
        );

        $mysql = <<<'EOS'
SELECT
    COUNT(`0`.`0`) AS `0`
    FROM (
        SELECT
            TRUE AS `0`
            FROM `People` AS `0`
            ORDER BY `0`.`Id` ASC
            LIMIT :0, :1
    ) AS `0`
EOS;

        $phpInput = <<<'EOS'
if (
    is_integer($input['stop']) && is_integer($input['start'])
) {
    $output = array(
        $input['start'],
        $input['stop'] - $input['start']
    );
} else {
    $output = null;
}
EOS;

        $phpOutput = <<<'EOS'
foreach ($input as $row) {
    $output = (integer)$row[0];
}
EOS;

        $this->verifyResult($scenario, $method, $arguments, $mysql, $phpInput,
            $phpOutput);
    }

    public function testCountSortFilter()
    {
        $scenario = self::getPeopleScenario();

        $method = <<<'EOS'
count(
    sort(filter(people, age < :minimumAge), age)
)
EOS;

        $arguments = array(
            'minimumAge' => 18
        );

        $mysql = <<<'EOS'
SELECT
    COUNT(TRUE) AS `0`
    FROM `People` AS `0`
    WHERE (`0`.`Age` < :0)
EOS;

        $phpInput = <<<'EOS'
if (is_integer($input['minimumAge']) || is_float($input['minimumAge'])) {
    $output = array(
        $input['minimumAge']
    );
} else {
    $output = null;
}
EOS;

        $phpOutput = <<<'EOS'
foreach ($input as $row) {
    $output = (integer)$row[0];
}
EOS;

        $this->verifyResult($scenario, $method, $arguments, $mysql, $phpInput,
            $phpOutput);
    }

    public function testCountFilterSort()
    {
        $scenario = self::getPeopleScenario();

        $method = <<<'EOS'
count(
    filter(sort(people, age), age < :minimumAge)
)
EOS;

        $arguments = array(
            'minimumAge' => 18
        );

        $mysql = <<<'EOS'
SELECT
    COUNT(TRUE) AS `0`
    FROM `People` AS `0`
    WHERE (`0`.`Age` < :0)
EOS;

        $phpInput = <<<'EOS'
if (is_integer($input['minimumAge']) || is_float($input['minimumAge'])) {
    $output = array(
        $input['minimumAge']
    );
} else {
    $output = null;
}
EOS;

        $phpOutput = <<<'EOS'
foreach ($input as $row) {
    $output = (integer)$row[0];
}
EOS;

        $this->verifyResult($scenario, $method, $arguments, $mysql, $phpInput,
            $phpOutput);
    }

    public function testCountSliceFilter()
    {
        $scenario = self::getPeopleScenario();

        $method = <<<'EOS'
count(
    slice(filter(people, age < :minimumAge), :start, :stop)
)
EOS;

        $arguments = array(
            'minimumAge' => 18,
            'start' => 0,
            'stop' => 3
        );

        $mysql = <<<'EOS'
SELECT
    COUNT(`0`.`0`) AS `0`
    FROM (
        SELECT
            TRUE AS `0`
            FROM `People` AS `0`
            WHERE (`0`.`Age` < :0)
            ORDER BY `0`.`Id` ASC
            LIMIT :1, :2
    ) AS `0`
EOS;

        $phpInput = <<<'EOS'
if (
    (
        is_integer($input['minimumAge']) && (
            is_integer($input['stop']) && is_integer($input['start'])
        )
    ) || (
        is_float($input['minimumAge']) && (
            is_integer($input['stop']) && is_integer($input['start'])
        )
    )
) {
    $output = array(
        $input['minimumAge'],
        $input['start'],
        $input['stop'] - $input['start']
    );
} else {
    $output = null;
}
EOS;

        $phpOutput = <<<'EOS'
foreach ($input as $row) {
    $output = (integer)$row[0];
}
EOS;

        $this->verifyResult($scenario, $method, $arguments, $mysql, $phpInput,
            $phpOutput);
    }

    public function testCountFilterSlice()
    {
        $scenario = self::getPeopleScenario();

        $method = <<<'EOS'
count(
    filter(slice(people, :start, :stop), age < :minimumAge)
)
EOS;

        $arguments = array(
            'minimumAge' => 18,
            'start' => 0,
            'stop' => 3
        );

        $mysql = <<<'EOS'
SELECT
    COUNT(`0`.`1`) AS `0`
    FROM (
        SELECT
            `0`.`Age` AS `0`,
            TRUE AS `1`
            FROM `People` AS `0`
            ORDER BY `0`.`Id` ASC
            LIMIT :0, :1
    ) AS `0`
    WHERE (`0`.`0` < :2)
EOS;

        $phpInput = <<<'EOS'
if (
    is_integer($input['stop']) && (
        is_integer($input['start']) && (is_integer($input['minimumAge']) || is_float($input['minimumAge']))
    )
) {
    $output = array(
        $input['start'],
        $input['stop'] - $input['start'],
        $input['minimumAge']
    );
} else {
    $output = null;
}
EOS;

        $phpOutput = <<<'EOS'
foreach ($input as $row) {
    $output = (integer)$row[0];
}
EOS;

        $this->verifyResult($scenario, $method, $arguments, $mysql, $phpInput,
            $phpOutput);
    }

    public function testCountSliceSort()
    {
        $scenario = self::getPeopleScenario();

        $method = <<<'EOS'
count(
    slice(sort(people, age), :start, :stop)
)
EOS;

        $arguments = array(
            'start' => 0,
            'stop' => 3
        );

        $mysql = <<<'EOS'
SELECT
    COUNT(`0`.`0`) AS `0`
    FROM (
        SELECT
            TRUE AS `0`
            FROM `People` AS `0`
            ORDER BY `0`.`Age` ASC
            LIMIT :0, :1
    ) AS `0`
EOS;

        $phpInput = <<<'EOS'
if (
    is_integer($input['stop']) && is_integer($input['start'])
) {
    $output = array(
        $input['start'],
        $input['stop'] - $input['start']
    );
} else {
    $output = null;
}
EOS;

        $phpOutput = <<<'EOS'
foreach ($input as $row) {
    $output = (integer)$row[0];
}
EOS;

        $this->verifyResult($scenario, $method, $arguments, $mysql, $phpInput,
            $phpOutput);
    }

    public function testCountSortSlice()
    {
        $scenario = self::getPeopleScenario();

        $method = <<<'EOS'
count(
    sort(slice(people, :start, :stop), age)
)
EOS;

        $arguments = array(
            'start' => 0,
            'stop' => 3
        );

        $mysql = <<<'EOS'
SELECT
    COUNT(`0`.`0`) AS `0`
    FROM (
        SELECT
            TRUE AS `0`
            FROM `People` AS `0`
            ORDER BY `0`.`Id` ASC
            LIMIT :0, :1
    ) AS `0`
EOS;

        $phpInput = <<<'EOS'
if (
    is_integer($input['stop']) && is_integer($input['start'])
) {
    $output = array(
        $input['start'],
        $input['stop'] - $input['start']
    );
} else {
    $output = null;
}
EOS;

        $phpOutput = <<<'EOS'
foreach ($input as $row) {
    $output = (integer)$row[0];
}
EOS;

        $this->verifyResult($scenario, $method, $arguments, $mysql, $phpInput,
            $phpOutput);
    }

    public function testCountSliceSortFilter()
    {
        $scenario = self::getPeopleScenario();

        $method = <<<'EOS'
count(
    slice(sort(filter(people, age < :minimumAge), age), :start, :stop)
)
EOS;

        $arguments = array(
            'minimumAge' => 18,
            'start' => 0,
            'stop' => 3
        );

        $mysql = <<<'EOS'
SELECT
    COUNT(`0`.`0`) AS `0`
    FROM (
        SELECT
            TRUE AS `0`
            FROM `People` AS `0`
            WHERE (`0`.`Age` < :0)
            ORDER BY `0`.`Age` ASC
            LIMIT :1, :2
    ) AS `0`
EOS;

        $phpInput = <<<'EOS'
if (
    (
        is_integer($input['minimumAge']) && (
            is_integer($input['stop']) && is_integer($input['start'])
        )
    ) || (
        is_float($input['minimumAge']) && (
            is_integer($input['stop']) && is_integer($input['start'])
        )
    )
) {
    $output = array(
        $input['minimumAge'],
        $input['start'],
        $input['stop'] - $input['start']
    );
} else {
    $output = null;
}
EOS;

        $phpOutput = <<<'EOS'
foreach ($input as $row) {
    $output = (integer)$row[0];
}
EOS;

        $this->verifyResult($scenario, $method, $arguments, $mysql, $phpInput,
            $phpOutput);
    }

    public function testCountSliceFilterSort()
    {
        $scenario = self::getPeopleScenario();

        $method = <<<'EOS'
count(
    slice(filter(sort(people, age), age < :minimumAge), :start, :stop)
)
EOS;

        $arguments = array(
            'minimumAge' => 18,
            'start' => 0,
            'stop' => 3
        );

        $mysql = <<<'EOS'
SELECT
    COUNT(`0`.`0`) AS `0`
    FROM (
        SELECT
            TRUE AS `0`
            FROM `People` AS `0`
            WHERE (`0`.`Age` < :0)
            ORDER BY `0`.`Age` ASC
            LIMIT :1, :2
    ) AS `0`
EOS;

        $phpInput = <<<'EOS'
if (
    (
        is_integer($input['minimumAge']) && (
            is_integer($input['stop']) && is_integer($input['start'])
        )
    ) || (
        is_float($input['minimumAge']) && (
            is_integer($input['stop']) && is_integer($input['start'])
        )
    )
) {
    $output = array(
        $input['minimumAge'],
        $input['start'],
        $input['stop'] - $input['start']
    );
} else {
    $output = null;
}
EOS;

        $phpOutput = <<<'EOS'
foreach ($input as $row) {
    $output = (integer)$row[0];
}
EOS;

        $this->verifyResult($scenario, $method, $arguments, $mysql, $phpInput,
            $phpOutput);
    }

    public function testCountSortSliceFilter()
    {
        $scenario = self::getPeopleScenario();

        $method = <<<'EOS'
count(
    sort(slice(filter(people, age < :minimumAge), :start, :stop), age)
)
EOS;

        $arguments = array(
            'minimumAge' => 18,
            'start' => 0,
            'stop' => 3
        );

        $mysql = <<<'EOS'
SELECT
    COUNT(`0`.`0`) AS `0`
    FROM (
        SELECT
            TRUE AS `0`
            FROM `People` AS `0`
            WHERE (`0`.`Age` < :0)
            ORDER BY `0`.`Id` ASC
            LIMIT :1, :2
    ) AS `0`
EOS;

        $phpInput = <<<'EOS'
if (
    (
        is_integer($input['minimumAge']) && (
            is_integer($input['stop']) && is_integer($input['start'])
        )
    ) || (
        is_float($input['minimumAge']) && (
            is_integer($input['stop']) && is_integer($input['start'])
        )
    )
) {
    $output = array(
        $input['minimumAge'],
        $input['start'],
        $input['stop'] - $input['start']
    );
} else {
    $output = null;
}
EOS;

        $phpOutput = <<<'EOS'
foreach ($input as $row) {
    $output = (integer)$row[0];
}
EOS;

        $this->verifyResult($scenario, $method, $arguments, $mysql, $phpInput,
            $phpOutput);
    }

    public function testCountSortFilterSlice()
    {
        $scenario = self::getPeopleScenario();

        $method = <<<'EOS'
count(
    sort(filter(slice(people, :start, :stop), age < :minimumAge), age)
)
EOS;

        $arguments = array(
            'minimumAge' => 18,
            'start' => 0,
            'stop' => 3
        );

        $mysql = <<<'EOS'
SELECT
    COUNT(`0`.`1`) AS `0`
    FROM (
        SELECT
            `0`.`Age` AS `0`,
            TRUE AS `1`
            FROM `People` AS `0`
            ORDER BY `0`.`Id` ASC
            LIMIT :0, :1
    ) AS `0`
    WHERE (`0`.`0` < :2)
EOS;

        $phpInput = <<<'EOS'
if (
    is_integer($input['stop']) && (
        is_integer($input['start']) && (is_integer($input['minimumAge']) || is_float($input['minimumAge']))
    )
) {
    $output = array(
        $input['start'],
        $input['stop'] - $input['start'],
        $input['minimumAge']
    );
} else {
    $output = null;
}
EOS;

        $phpOutput = <<<'EOS'
foreach ($input as $row) {
    $output = (integer)$row[0];
}
EOS;

        $this->verifyResult($scenario, $method, $arguments, $mysql, $phpInput,
            $phpOutput);
    }

    public function testCountFilterSliceSort()
    {
        $scenario = self::getPeopleScenario();

        $method = <<<'EOS'
count(
    filter(slice(sort(people, age), :start, :stop), age < :minimumAge)
)
EOS;

        $arguments = array(
            'minimumAge' => 18,
            'start' => 0,
            'stop' => 3
        );

        $mysql = <<<'EOS'
SELECT
    COUNT(`0`.`1`) AS `0`
    FROM (
        SELECT
            `0`.`Age` AS `0`,
            TRUE AS `1`
            FROM `People` AS `0`
            ORDER BY `0`.`Age` ASC
            LIMIT :0, :1
    ) AS `0`
    WHERE (`0`.`0` < :2)
EOS;

    $phpInput = <<<'EOS'
if (
    is_integer($input['stop']) && (
        is_integer($input['start']) && (is_integer($input['minimumAge']) || is_float($input['minimumAge']))
    )
) {
    $output = array(
        $input['start'],
        $input['stop'] - $input['start'],
        $input['minimumAge']
    );
} else {
    $output = null;
}
EOS;

        $phpOutput = <<<'EOS'
foreach ($input as $row) {
    $output = (integer)$row[0];
}
EOS;

        $this->verifyResult($scenario, $method, $arguments, $mysql, $phpInput,
            $phpOutput);
    }

    public function testCountFilterSortSlice()
    {
        $scenario = self::getPeopleScenario();

        $method = <<<'EOS'
count(
    filter(sort(slice(people, :start, :stop), age), age < :minimumAge)
)
EOS;

        $arguments = array(
            'minimumAge' => 18,
            'start' => 0,
            'stop' => 3
        );

        $mysql = <<<'EOS'
SELECT
    COUNT(`0`.`1`) AS `0`
    FROM (
        SELECT
            `0`.`Age` AS `0`,
            TRUE AS `1`
            FROM `People` AS `0`
            ORDER BY `0`.`Id` ASC
            LIMIT :0, :1
    ) AS `0`
    WHERE (`0`.`0` < :2)
EOS;

        $phpInput = <<<'EOS'
if (
    is_integer($input['stop']) && (
        is_integer($input['start']) && (is_integer($input['minimumAge']) || is_float($input['minimumAge']))
    )
) {
    $output = array(
        $input['start'],
        $input['stop'] - $input['start'],
        $input['minimumAge']
    );
} else {
    $output = null;
}
EOS;

        $phpOutput = <<<'EOS'
foreach ($input as $row) {
    $output = (integer)$row[0];
}
EOS;

        $this->verifyResult($scenario, $method, $arguments, $mysql, $phpInput,
            $phpOutput);
    }

    public function testSum()
    {
        $scenario = self::getPeopleScenario();

        $method = <<<'EOS'
sum(
    people,
    age
)
EOS;

        $arguments = array();

        $mysql = <<<'EOS'
SELECT
    SUM(`0`.`Age`) AS `0`
    FROM `People` AS `0`
EOS;

        $phpInput = <<<'EOS'
$output = array();
EOS;

        $phpOutput = <<<'EOS'
foreach ($input as $row) {
    $output = isset($row[0]) ? (integer)$row[0] : null;
}
EOS;

        $this->verifyResult($scenario, $method, $arguments, $mysql, $phpInput,
            $phpOutput);
    }

    public function testSumSort()
    {
        $scenario = self::getPeopleScenario();

        $method = <<<'EOS'
sum(
    sort(people, age),
    age
)
EOS;

        $arguments = array();

        $mysql = <<<'EOS'
SELECT
    SUM(`0`.`Age`) AS `0`
    FROM `People` AS `0`
EOS;

        $phpInput = <<<'EOS'
$output = array();
EOS;

        $phpOutput = <<<'EOS'
foreach ($input as $row) {
    $output = isset($row[0]) ? (integer)$row[0] : null;
}
EOS;

        $this->verifyResult($scenario, $method, $arguments, $mysql, $phpInput,
            $phpOutput);
    }

    public function testSumFilter()
    {
        $scenario = self::getPeopleScenario();

        $method = <<<'EOS'
sum(
    filter(people, age < :minimumAge),
    age
)
EOS;

        $arguments = array(
            'minimumAge' => 18
        );

        $mysql = <<<'EOS'
SELECT
    SUM(`0`.`Age`) AS `0`
    FROM `People` AS `0`
    WHERE (`0`.`Age` < :0)
EOS;

        $phpInput = <<<'EOS'
if (is_integer($input['minimumAge']) || is_float($input['minimumAge'])) {
    $output = array(
        $input['minimumAge']
    );
} else {
    $output = null;
}
EOS;

        $phpOutput = <<<'EOS'
foreach ($input as $row) {
    $output = isset($row[0]) ? (integer)$row[0] : null;
}
EOS;

        $this->verifyResult($scenario, $method, $arguments, $mysql, $phpInput,
            $phpOutput);
    }

    public function testSumSlice()
    {
        $scenario = self::getPeopleScenario();

        $method = <<<'EOS'
sum(
    slice(people, :start, :stop),
    age
)
EOS;

        $arguments = array(
            'start' => 0,
            'stop' => 3
        );

        $mysql = <<<'EOS'
SELECT
    SUM(`0`.`0`) AS `0`
    FROM (
        SELECT
            `0`.`Age` AS `0`
            FROM `People` AS `0`
            ORDER BY `0`.`Id` ASC
            LIMIT :0, :1
    ) AS `0`
EOS;

        $phpInput = <<<'EOS'
if (
    is_integer($input['stop']) && is_integer($input['start'])
) {
    $output = array(
        $input['start'],
        $input['stop'] - $input['start']
    );
} else {
    $output = null;
}
EOS;

        $phpOutput = <<<'EOS'
foreach ($input as $row) {
    $output = isset($row[0]) ? (integer)$row[0] : null;
}
EOS;

        $this->verifyResult($scenario, $method, $arguments, $mysql, $phpInput,
            $phpOutput);
    }

    public function testSumSliceSortFilter()
    {
        $scenario = self::getPeopleScenario();

        $method = <<<'EOS'
sum(
    slice(sort(filter(people, age < :minimumAge), age), :start, :stop),
    age
)
EOS;

        $arguments = array(
            'minimumAge' => 18,
            'start' => 0,
            'stop' => 3
        );

        $mysql = <<<'EOS'
SELECT
    SUM(`0`.`0`) AS `0`
    FROM (
        SELECT
            `0`.`Age` AS `0`
            FROM `People` AS `0`
            WHERE (`0`.`Age` < :0)
            ORDER BY `0`.`Age` ASC
            LIMIT :1, :2
    ) AS `0`
EOS;

        $phpInput = <<<'EOS'
if (
    (
        is_integer($input['minimumAge']) && (
            is_integer($input['stop']) && is_integer($input['start'])
        )
    ) || (
        is_float($input['minimumAge']) && (
            is_integer($input['stop']) && is_integer($input['start'])
        )
    )
) {
    $output = array(
        $input['minimumAge'],
        $input['start'],
        $input['stop'] - $input['start']
    );
} else {
    $output = null;
}
EOS;

        $phpOutput = <<<'EOS'
foreach ($input as $row) {
    $output = isset($row[0]) ? (integer)$row[0] : null;
}
EOS;

        $this->verifyResult($scenario, $method, $arguments, $mysql, $phpInput,
            $phpOutput);
    }

    public function testDelete()
    {
        $scenario = self::getPeopleScenario();

        $method = <<<'EOS'
delete(
    people
)
EOS;

        $arguments = array();

        $mysql = <<<'EOS'
DELETE
    FROM `People`
EOS;

        $phpInput = <<<'EOS'
$output = array();
EOS;

        $phpOutput = <<<'EOS'
$output = null;
EOS;

        $this->verifyResult($scenario, $method, $arguments, $mysql, $phpInput,
            $phpOutput);
    }

    public function testDeleteFilter()
    {
        $scenario = self::getPeopleScenario();

        $method = <<<'EOS'
delete(
    filter(people, age < :age)
)
EOS;

        $arguments = array(
            'age' => 21
        );

        $mysql = <<<'EOS'
DELETE
    FROM `People`
    WHERE (`People`.`Age` < :0)
EOS;

        $phpInput = <<<'EOS'
if (is_integer($input['age']) || is_float($input['age'])) {
    $output = array(
        $input['age']
    );
} else {
    $output = null;
}
EOS;

        $phpOutput = <<<'EOS'
$output = null;
EOS;

        $this->verifyResult($scenario, $method, $arguments, $mysql, $phpInput,
            $phpOutput);
    }

    /**
     * Note: MySQL requires ":start = 0". No other value is possible in MySQL!
     * When a user supplies a non-zero start value, Cinnabari should simply
     * reject the request and provide an explanation.
     *
     * Note: MySQL behavior is unpredictable when a "LIMIT" clause is used
     * without an "ORDER BY" clause. That's why the "sort" method and the
     * "slice" method are tested together here.
     *
     * Because of this unpredictable behavior, Cinnabari should--at some point
     * in the future--insert an implicit "sort" function (using the identifier
     * expression) when a user-supplied query lacks an explicit "sort" function.
     *
     * The following unit test, however, is valid and will always be valid:
     */
    public function testDeleteSliceSort()
    {
        $scenario = self::getPeopleScenario();

        $method = <<<'EOS'
delete(
    slice(sort(people, age), :start, :stop)
)
EOS;

        $arguments = array(
            'start' => 0,
            'stop' => 2
        );

        $mysql = <<<'EOS'
DELETE
    FROM `People`
    ORDER BY `People`.`Age` ASC
    LIMIT :0
EOS;

        $phpInput = <<<'EOS'
if (
    is_integer($input['stop']) && is_integer($input['start'])
) {
    $output = array(
        $input['stop'] - $input['start']
    );
} else {
    $output = null;
}
EOS;

        $phpOutput = <<<'EOS'
$output = null;
EOS;

        $this->verifyResult($scenario, $method, $arguments, $mysql, $phpInput,
            $phpOutput);
    }

    public function testDeleteSliceSortFilter()
    {
        $scenario = self::getPeopleScenario();

        $method = <<<'EOS'
delete(
    slice(sort(filter(people, :age <= age), age), :start, :stop)
)
EOS;

        $arguments = array(
            'age' => 18,
            'start' => 0,
            'stop' => 2
        );

        $mysql = <<<'EOS'
DELETE
    FROM `People`
    WHERE (:0 <= `People`.`Age`)
    ORDER BY `People`.`Age` ASC
    LIMIT :1
EOS;

        $phpInput = <<<'EOS'
if (
    (
        is_integer($input['age']) && (
            is_integer($input['stop']) && is_integer($input['start'])
        )
    ) || (
        is_float($input['age']) && (
            is_integer($input['stop']) && is_integer($input['start'])
        )
    )
) {
    $output = array(
        $input['age'],
        $input['stop'] - $input['start']
    );
} else {
    $output = null;
}
EOS;

        $phpOutput = <<<'EOS'
$output = null;
EOS;

        $this->verifyResult($scenario, $method, $arguments, $mysql, $phpInput,
            $phpOutput);
    }

    public function testInsert()
    {
        $scenario = self::getPeopleScenario();

        $method = <<<'EOS'
insert(
    people,
    {
        "name": :name,
        "email": :email,
        "age": :age,
        "height": :height,
        "isMarried": :isMarried
    }
)
EOS;

        $arguments = array(
            'isMarried' => false,
            'age' => 28,
            'height' => 5.3,
            'name' => 'Eva',
            'email' => 'eva@example.com'
        );

        $mysql = <<<'EOS'
INSERT
    INTO `People`
    SET
        `Name` = :0,
        `Email` = :1,
        `Age` = :2,
        `Height` = :3,
        `Married` = :4
EOS;

        $phpInput = <<<'EOS'
if (
    (
        is_null($input['name']) || is_string($input['name'])
    ) && (
        (
            is_null($input['email']) || is_string($input['email'])
        ) && (
            (
                is_null($input['age']) || is_integer($input['age'])
            ) && (
                (
                    is_null($input['height']) || is_float($input['height'])
                ) && (is_null($input['isMarried']) || is_bool($input['isMarried']))
            )
        )
    )
) {
    $output = array(
        $input['name'],
        $input['email'],
        $input['age'],
        $input['height'],
        $input['isMarried']
    );
} else {
    $output = null;
}
EOS;

        $phpOutput = <<<'EOS'
$output = null;
EOS;

        $this->verifyResult($scenario, $method, $arguments, $mysql, $phpInput, $phpOutput);
    }

    public function testSet()
    {
        $scenario = self::getPeopleScenario();

        $method = <<<'EOS'
set(
    people,
    {
        "name": :name
    }
)
EOS;

        $arguments = array(
            'name' => 'Nemo'
        );

        $mysql = <<<'EOS'
UPDATE
    `People` AS `0`
    SET
        `0`.`Name` = :0
EOS;

        $phpInput = <<<'EOS'
if (is_null($input['name']) || is_string($input['name'])) {
    $output = array(
        $input['name']
    );
} else {
    $output = null;
}
EOS;

        $phpOutput = <<<'EOS'
$output = null;
EOS;

        $this->verifyResult($scenario, $method, $arguments, $mysql, $phpInput, $phpOutput);
    }

    public function testSetFilter()
    {
        $scenario = self::getPeopleScenario();

        $method = <<<'EOS'
set(
    filter(people, age < :age),
    {
        "name": :name,
        "age": :age
    }
)
EOS;

        $arguments = array(
            'name' => 'Nemo',
            'age' => 18
        );

        $mysql = <<<'EOS'
UPDATE
    `People` AS `0`
    SET
        `0`.`Name` = :1,
        `0`.`Age` = :0
    WHERE (`0`.`Age` < :0)
EOS;

        $phpInput = <<<'EOS'
if (
    (
        is_null($input['age']) || is_integer($input['age'])
    ) && (is_null($input['name']) || is_string($input['name']))
) {
    $output = array(
        $input['age'],
        $input['name']
    );
} else {
    $output = null;
}
EOS;

        $phpOutput = <<<'EOS'
$output = null;
EOS;

        $this->verifyResult($scenario, $method, $arguments, $mysql, $phpInput, $phpOutput);
    }

    public function testSetSliceSort()
    {
        $scenario = self::getPeopleScenario();

        $method = <<<'EOS'
set(
    slice(sort(people, age), :start, :end),
    {
        "name": :name,
        "age": :age
    }
)
EOS;

        $arguments = array(
            'name' => 'Nemo',
            'age' => 18,
            'start' => 0,
            'end' => 10
        );

        $mysql = <<<'EOS'
UPDATE
    `People` AS `0`
    SET
        `0`.`Name` = :1,
        `0`.`Age` = :2
    ORDER BY `0`.`Age` ASC
    LIMIT :0
EOS;

        $phpInput = <<<'EOS'
if (
    is_integer($input['end']) && (
        is_integer($input['start']) && (
            (
                is_null($input['name']) || is_string($input['name'])
            ) && (is_null($input['age']) || is_integer($input['age']))
        )
    )
) {
    $output = array(
        $input['end'] - $input['start'],
        $input['name'],
        $input['age']
    );
} else {
    $output = null;
}
EOS;

        $phpOutput = <<<'EOS'
$output = null;
EOS;

        $this->verifyResult($scenario, $method, $arguments, $mysql, $phpInput, $phpOutput);
    }

    public function testSetSliceSortFilter()
    {
        $scenario = self::getPeopleScenario();

        $method = <<<'EOS'
set(
    slice(sort(filter(people, age < :age), age), :start, :end),
    {
        "name": :name,
        "age": :age
    }
)
EOS;

        $arguments = array(
            'name' => 'Nemo',
            'age' => 18,
            'start' => 0,
            'end' => 10
        );

        $mysql = <<<'EOS'
UPDATE
    `People` AS `0`
    SET
        `0`.`Name` = :2,
        `0`.`Age` = :0
    WHERE (`0`.`Age` < :0)
    ORDER BY `0`.`Age` ASC
    LIMIT :1
EOS;

        $phpInput = <<<'EOS'
if (
    (
        is_null($input['age']) || is_integer($input['age'])
    ) && (
        is_integer($input['end']) && (
            is_integer($input['start']) && (is_null($input['name']) || is_string($input['name']))
        )
    )
) {
    $output = array(
        $input['age'],
        $input['end'] - $input['start'],
        $input['name']
    );
} else {
    $output = null;
}
EOS;

        $phpOutput = <<<'EOS'
$output = null;
EOS;

        $this->verifyResult($scenario, $method, $arguments, $mysql, $phpInput, $phpOutput);
    }

    private function verifyResult($scenarioJson, $method, $arguments, $mysql, $phpInput, $phpOutput)
    {
        $actual = self::translate($scenarioJson, $method, $arguments);
        $expected = array($mysql, $phpInput, $phpOutput);

        // these assersions are separated to improve PHPUnit's output
        $this->assertSame(
            self::standardizeMysql($expected[0]),
            self::standardizeMysql($actual[0])
        );
        $this->assertSame(
            self::standardizePhp($expected[1]),
            self::standardizePhp($actual[1])
        );
        $this->assertSame(
            self::standardizePhp($expected[2]),
            self::standardizePhp($actual[2])
        );
    }

    private function verifyException($scenarioJson, $method, $arguments, $code, $data)
    {
        $expected = array(
            'code' => $code,
            'data' => $data
        );

        try {
            self::translate($scenarioJson, $method, $arguments);
            $actual = null;
        } catch (CinnabariException $exception) {
            $actual = array(
                'code' => $exception->getCode(),
                'data' => $exception->getData()
            );
        }

        $this->assertSame($expected, $actual);
    }

    private static function translate($scenarioJson, $method, $arguments)
    {
        $scenario = json_decode($scenarioJson, true);

        $cinnabari = new Cinnabari($scenario);
        return $cinnabari->translate($method, $arguments);
    }

    private static function standardize($artifact)
    {
        list($mysql, $phpInput, $phpOutput) = $artifact;

        return array(
            self::standardizeMysql($mysql),
            self::standardizePhp($phpInput),
            self::standardizePhp($phpOutput)
        );
    }

    private static function standardizePhp($php)
    {
        return preg_replace('~\t~', '    ', $php);
    }

    private static function standardizeMysql($mysql)
    {
        $mysql = preg_replace('~\s+~', ' ', $mysql);

        // Remove any unnecessary whitespace after an opening parenthesis
        // Example: "( `" => "(`"
        // Example: "( (" => "(("
        // Example: "( :" => "(:"
        $mysql = preg_replace('~\( (?=`|\(|:)~', '(', $mysql);

        // Remove any unnecessary whitespace before a closing parenthesis
        // Example: "` )" => "`)"
        // Example: ") )" => "))"
        $mysql = preg_replace('~(?<=`|\)) \)~', ')', $mysql);

        return $mysql;
    }
}
