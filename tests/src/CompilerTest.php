<?php

namespace Datto\Cinnabari\Tests;

use Datto\Cinnabari\Compiler;
use Datto\Cinnabari\Lexer;
use Datto\Cinnabari\Parser;
use PHPUnit_Framework_TestCase;

class CompilerTest extends PHPUnit_Framework_TestCase
{
    /** @var Schema */
    private $schema;

    public function __construct()
    {
        $this->schema = new Schema();
        parent::__construct();
    }

    public function testDevicesMapId()
    {
        $method = 'zabulus.devices.map(id)';

        $arguments = array();

        $mysql = <<<'EOS'
SELECT
    `0`.`deviceID` AS `0`
    FROM `device` AS `0`
EOS;

        $phpInput = <<<'EOS'
$output = array();
EOS;

        $phpOutput = <<<'EOS'
foreach ($input as $row) {
    $output[$row['0']] = ($row['0'] === null ? null : (integer)$row['0']);
}

$output = is_array($output) ? array_values($output) : array();
EOS;

        $this->verify($method, $arguments, $mysql, $phpInput, $phpOutput);
    }

    public function testDevicesMapMacAddress()
    {
        $method = 'zabulus.devices.map(macAddress)';

        $arguments = array();

        $mysql = <<<'EOS'
SELECT
    IF(`0`.`mac` <=> '', NULL, LOWER(`0`.`mac`)) AS `0`,
    `0`.`deviceID` AS `1`
    FROM `device` AS `0`
EOS;

        $phpInput = <<<'EOS'
$output = array();
EOS;

        $phpOutput = <<<'EOS'
foreach ($input as $row) {
    $output[$row['1']] = $row['0'];
}

$output = is_array($output) ? array_values($output) : array();
EOS;

        $this->verify($method, $arguments, $mysql, $phpInput, $phpOutput);
    }

    public function testDevicesMapIdMacAddress()
    {
        $method = 'zabulus.devices.map({"ID": id, "MAC": macAddress})';

        $arguments = array();

        $mysql = <<<'EOS'
SELECT
    `0`.`deviceID` AS `0`,
    IF(`0`.`mac` <=> '', NULL, LOWER(`0`.`mac`)) AS `1`
    FROM `device` AS `0`
EOS;

        $phpInput = <<<'EOS'
$output = array();
EOS;

        $phpOutput = <<<'EOS'
foreach ($input as $row) {
    $x0 = &$output[$row['0']];
    $x0['ID'] = ($row['0'] === null ? null : (integer)$row['0']);
    $x0['MAC'] = $row['1'];
}

$output = is_array($output) ? array_values($output) : array();
EOS;

        $this->verify($method, $arguments, $mysql, $phpInput, $phpOutput);
    }

    public function testDevicesFilterMacAddressMapResellerId()
    {
        $method = 'zabulus.devices.filter(macAddress = :macAddress).map(resellerId)';

        $arguments = array(
            'macAddress' => '00012e6bc2c1'
        );

        $mysql = <<<'EOS'
SELECT
    `0`.`resellerID` AS `0`,
    `0`.`deviceID` AS `1`
    FROM `device` AS `0`
    WHERE (IF(`0`.`mac` <=> '', NULL, LOWER(`0`.`mac`)) <=> :0)
EOS;

        $phpInput = <<<'EOS'
$output = array(
    $input['macAddress']
);
EOS;

        $phpOutput = <<<'EOS'
foreach ($input as $row) {
    $output[$row['1']] = ($row['0'] === null ? null : (integer)$row['0']);
}

$output = is_array($output) ? array_values($output) : array();
EOS;

        $this->verify($method, $arguments, $mysql, $phpInput, $phpOutput);
    }

    private function verify($method, $arguments, $mysql, $phpInput, $phpOutput)
    {
        $expected = self::standardize(array($mysql, $phpInput, $phpOutput));

        $lexer = new Lexer();
        $parser = new Parser();
        $schema = new Schema();
        $compiler = new Compiler($schema);

        $tokens = $lexer->tokenize($method);
        $request = $parser->parse($tokens);
        $actual = self::standardize($compiler->compile($request, $arguments));

        $this->assertSame($expected, $actual);
    }

    private static function standardize($result)
    {
        list($mysql, $phpInput, $phpOutput) = $result;

        return array(
            self::compressWhitespace($mysql),
            self::compressWhitespace($phpInput),
            self::compressWhitespace($phpOutput)
        );
    }

    private static function compressWhitespace($input)
    {
        return preg_replace('~\s+~', ' ', $input);
    }
}
