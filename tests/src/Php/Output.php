<?php

namespace Datto\Cinnabari\Tests\Php;

use Datto\Cinnabari\Php\Output;
use Datto\Cinnabari\Tests\TestUtils;
use PHPUnit_Framework_TestCase;

class OutputTest extends PHPUnit_Framework_TestCase
{
    public function testRequiredBoolean()
    {
        $actual = Output::getList(0, false, true,
            Output::getValue(1, false, Output::TYPE_BOOLEAN)
        );

        $expected = <<<'EOS'
foreach ($input as $row) {
    $output[$row[0]] = (boolean)$row[1];
}

$output = isset($output) ? array_values($output) : array();
EOS;

        $this->verify($expected, $actual);
    }

    public function testOptionalBoolean()
    {
        $actual = Output::getList(0, false, true,
            Output::getValue(1, true, Output::TYPE_BOOLEAN)
        );

        $expected = <<<'EOS'
foreach ($input as $row) {
    $output[$row[0]] = isset($row[1]) ? (boolean)$row[1] : null;
}

$output = isset($output) ? array_values($output) : array();
EOS;

        $this->verify($expected, $actual);
    }

    public function testRequiredInteger()
    {
        $actual = Output::getList(0, false, true,
            Output::getValue(1, false, Output::TYPE_INTEGER)
        );

        $expected = <<<'EOS'
foreach ($input as $row) {
    $output[$row[0]] = (integer)$row[1];
}

$output = isset($output) ? array_values($output) : array();
EOS;

        $this->verify($expected, $actual);
    }

    public function testOptionalInteger()
    {
        $actual = Output::getList(0, false, true,
            Output::getValue(1, true, Output::TYPE_INTEGER)
        );

        $expected = <<<'EOS'
foreach ($input as $row) {
    $output[$row[0]] = isset($row[1]) ? (integer)$row[1] : null;
}

$output = isset($output) ? array_values($output) : array();
EOS;

        $this->verify($expected, $actual);
    }

    public function testRequiredFloat()
    {
        $actual = Output::getList(0, false, true,
            Output::getValue(1, false, Output::TYPE_FLOAT)
        );

        $expected = <<<'EOS'
foreach ($input as $row) {
    $output[$row[0]] = (float)$row[1];
}

$output = isset($output) ? array_values($output) : array();
EOS;

        $this->verify($expected, $actual);
    }

    public function testOptionalFloat()
    {
        $actual = Output::getList(0, false, true,
            Output::getValue(1, true, Output::TYPE_FLOAT)
        );

        $expected = <<<'EOS'
foreach ($input as $row) {
    $output[$row[0]] = isset($row[1]) ? (float)$row[1] : null;
}

$output = isset($output) ? array_values($output) : array();
EOS;

        $this->verify($expected, $actual);
    }

    public function testRequiredString()
    {
        $actual = Output::getList(0, false, true,
            Output::getValue(1, false, Output::TYPE_STRING)
        );

        $expected = <<<'EOS'
foreach ($input as $row) {
    $output[$row[0]] = $row[1];
}

$output = isset($output) ? array_values($output) : array();
EOS;

        $this->verify($expected, $actual);
    }

    public function testOptionalString()
    {
        $actual = Output::getList(0, false, true,
            Output::getValue(1, true, Output::TYPE_STRING)
        );

        $expected = <<<'EOS'
foreach ($input as $row) {
    $output[$row[0]] = $row[1];
}

$output = isset($output) ? array_values($output) : array();
EOS;

        $this->verify($expected, $actual);
    }

    public function testObject()
    {
        $actual = Output::getList(0, false, true,
            Output::getObject(
                array(
                    'first' => Output::getValue(1, true, Output::TYPE_STRING),
                    'last' => Output::getValue(2, true, Output::TYPE_STRING)
                )
            )
        );

        $expected = <<<'EOS'
foreach ($input as $row) {
    $output[$row[0]]['first'] = $row[1];
    $output[$row[0]]['last'] = $row[2];
}

$output = isset($output) ? array_values($output) : array();
EOS;

        $this->verify($expected, $actual);
    }

    public function testListOne()
    {
        $actual = Output::getList(0, false, true,
            Output::getList(null, false, false,
                Output::getValue(1, false, Output::TYPE_INTEGER)
            )
        );

        $expected = <<<'EOS'
foreach ($input as $row) {
    $output[$row[0]] = (integer)$row[1];
}

$output = isset($output) ? array_values($output) : array();
EOS;

        $this->verify($expected, $actual);
    }

    public function testListZeroOne()
    {
        $actual = Output::getList(0, false, true,
            Output::getList(1, true, false,
                Output::getValue(2, false, Output::TYPE_INTEGER)
            )
        );

        $expected = <<<'EOS'
foreach ($input as $row) {
    if (isset($row[1])) {
        $output[$row[0]] = (integer)$row[2];
    }
}

$output = isset($output) ? array_values($output) : array();
EOS;

        $this->verify($expected, $actual);
    }

    public function testListOneMany()
    {
        $actual = Output::getList(0, false, true,
            Output::getList(1, false, true,
                Output::getValue(2, false, Output::TYPE_INTEGER)
            )
        );

        $expected = <<<'EOS'
foreach ($input as $row) {
    $output[$row[0]][$row[1]] = (integer)$row[2];
}

$output = isset($output) ? array_values($output) : array();

foreach ($output as &$x0) {
    $x0 = isset($x0) ? array_values($x0) : array();
}
EOS;

        $this->verify($expected, $actual);
    }

    public function testListZeroOneMany()
    {
        $actual = Output::getList(0, false, true,
            Output::getList(1, true, true,
                Output::getValue(2, false, Output::TYPE_INTEGER)
            )
        );

        $expected = <<<'EOS'
foreach ($input as $row) {
    if (isset($row[1])) {
        $output[$row[0]][$row[1]] = (integer)$row[2];
    }
}

$output = isset($output) ? array_values($output) : array();

foreach ($output as &$x0) {
    $x0 = isset($x0) ? array_values($x0) : array();
}
EOS;

        $this->verify($expected, $actual);
    }

    public function testListList()
    {
        $actual = Output::getList(0, false, true,
            Output::getList(1, true, true,
                Output::getList(2, true, true,
                    Output::getValue(3, false, Output::TYPE_INTEGER)
                )
            )
        );

        $expected = <<<'EOS'
foreach ($input as $row) {
    if (isset($row[1])) {
        if (isset($row[2])) {
            $output[$row[0]][$row[1]][$row[2]] = (integer)$row[3];
        }
    }
}

$output = isset($output) ? array_values($output) : array();

foreach ($output as &$x1) {
    $x1 = isset($x1) ? array_values($x1) : array();

    foreach ($x1 as &$x0) {
        $x0 = isset($x0) ? array_values($x0) : array();
    }
}
EOS;

        $this->verify($expected, $actual);
    }

    public function testObjectLists()
    {
        $actual = Output::getList(4, true, true,
            Output::getObject(
                array(
                    'read' => Output::getList(0, true, true,
                        Output::getValue(1, false, Output::TYPE_INTEGER)
                    ),
                    'unread' => Output::getList(2, true, true,
                        Output::getValue(3, false, Output::TYPE_INTEGER)
                    )
                )
            )
        );

        $expected = <<<'EOS'
foreach ($input as $row) {
    if (isset($row[4])) {
        if (isset($row[0])) {
            $output[$row[4]]['read'][$row[0]] = (integer)$row[1];
        }

        if (isset($row[2])) {
            $output[$row[4]]['unread'][$row[2]] = (integer)$row[3];
        }
    }
}

$output = isset($output) ? array_values($output) : array();

foreach ($output as &$x0) {
    $x0['read'] = isset($x0['read']) ? array_values($x0['read']) : array();
    $x0['unread'] = isset($x0['unread']) ? array_values($x0['unread']) : array();
}
EOS;

        $this->verify($expected, $actual);
    }

    private function verify($expected, $actual)
    {
        $this->assertSame(
            TestUtils::removePHPWhitespace($expected),
            TestUtils::removePHPWhitespace($actual)
        );
    }
}
