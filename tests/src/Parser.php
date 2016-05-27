<?php

namespace Datto\Cinnabari\Tests;

use Datto\Cinnabari\Exception;
use Datto\Cinnabari\Lexer;
use Datto\Cinnabari\Parser;
use PHPUnit_Framework_TestCase;

class ParserTest extends PHPUnit_Framework_TestCase
{
    /** @var Parser */
    private $parser;

    public function __construct()
    {
        parent::__construct();

        $this->parser = new Parser();
    }

    /*
    public function testInputNull()
    {
        $input = null;

        $exceptionData = null;

        $this->verifyException($input, $exceptionData);
    }

    public function testInputString()
    {
        $input = 'devices.map(id)';

        $exceptionData = null;

        $this->verifyException($input, $exceptionData);
    }

    public function testInputEmptyArray()
    {
        $input = array();

        $exceptionData = array(false, null, null);

        $this->verifyException($input, $exceptionData);
    }

    public function testUnexpectedTokenNull()
    {
        $input = array(
            array(Lexer::TYPE_PARAMETER => 'x'),
            array(Lexer::TYPE_OPERATOR => '.'),
            null
        );

        $exceptionData = array(true, 2, null);

        $this->verifyException($input, $exceptionData);
    }

    public function testUnexpectedTokenLengthZero()
    {
        $input = array(
            array(Lexer::TYPE_PARAMETER => 'x'),
            array(Lexer::TYPE_OPERATOR => '.'),
            array()
        );

        $exceptionData = array(true, 2, array(false, null, null));

        $this->verifyException($input, $exceptionData);
    }

    public function testUnexpectedTokenLengthTwo()
    {
        $input = array(
            array(Lexer::TYPE_PARAMETER => 'x'),
            array(Lexer::TYPE_OPERATOR => '.'),
            array(Lexer::TYPE_PARAMETER => 'y', Lexer::TYPE_OPERATOR => '.')
        );

        $exceptionData = array(true, 2, array(false, Lexer::TYPE_OPERATOR, null));

        $this->verifyException($input, $exceptionData);
    }

    public function testUnexpectedTokenType()
    {
        $input = array(
            array(Lexer::TYPE_PARAMETER => 'x'),
            array(Lexer::TYPE_OPERATOR => '.'),
            array(-1 => 'y')
        );

        $exceptionData = array(true, 2, array(false, -1, null));

        $this->verifyException($input, $exceptionData);
    }

    public function testUnexpectedTokenValue()
    {
        $input = array(
            array(Lexer::TYPE_PARAMETER => 'x'),
            array(Lexer::TYPE_OPERATOR => '.'),
            array(Lexer::TYPE_PARAMETER => null)
        );

        $exceptionData = array(true, 2, array(true, Lexer::TYPE_PARAMETER, null));

        $this->verifyException($input, $exceptionData);
    }
    */

    public function testParameterToken()
    {
        $input = array(
            array(Lexer::TYPE_PARAMETER => 'x')
        );

        $output = array(Parser::TYPE_PARAMETER, 'x');

        $this->verifyOutput($input, $output);
    }

    public function testPropertyToken()
    {
        $input = array(
            array(Lexer::TYPE_PROPERTY => 'x')
        );

        $output = array(Parser::TYPE_PROPERTY, 'x');

        $this->verifyOutput($input, $output);
    }

    public function testFunctionTokenNoArguments()
    {
        $input = array(
            array(Lexer::TYPE_FUNCTION => array('f'))
        );

        $output = array(Parser::TYPE_FUNCTION, 'f');

        $this->verifyOutput($input, $output);
    }

    public function testFunctionTokenOneArgument()
    {
        $input = array(
            array(Lexer::TYPE_FUNCTION => array(
                'f',
                array(
                    array(Lexer::TYPE_PARAMETER => 'x')
                )
            ))
        );

        $output = array(Parser::TYPE_FUNCTION, 'f',
            array(Parser::TYPE_PARAMETER, 'x')
        );

        $this->verifyOutput($input, $output);
    }

    public function testFunctionTokenTwoArguments()
    {
        $input = array(
            array(Lexer::TYPE_FUNCTION => array(
                'f',
                array(
                    array(Lexer::TYPE_PARAMETER => 'x')
                ),
                array(
                    array(Lexer::TYPE_PROPERTY => 'y')
                )
            ))
        );

        $output = array(Parser::TYPE_FUNCTION, 'f',
            array(Parser::TYPE_PARAMETER, 'x'),
            array(Parser::TYPE_PROPERTY, 'y')
        );

        $this->verifyOutput($input, $output);
    }

    public function testObjectTokenOneKey()
    {
        $input = array(
            array(Lexer::TYPE_OBJECT => array(
                'a' => array(
                    array(Lexer::TYPE_PARAMETER => 'x')
                )
            ))
        );

        $output = array(Parser::TYPE_OBJECT, array(
            'a' => array(Parser::TYPE_PARAMETER, 'x')
        )
        );

        $this->verifyOutput($input, $output);
    }

    public function testObjectTokenTwoKeys()
    {
        $input = array(
            array(Lexer::TYPE_OBJECT => array(
                'a' => array(
                    array(Lexer::TYPE_PARAMETER => 'x')
                ),
                'b' => array(
                    array(Lexer::TYPE_PROPERTY => 'y')
                )
            ))
        );

        $output = array(Parser::TYPE_OBJECT, array(
            'a' => array(Parser::TYPE_PARAMETER, 'x'),
            'b' => array(Parser::TYPE_PROPERTY, 'y')
        ));

        $this->verifyOutput($input, $output);
    }

    public function testGroupToken()
    {
        $input = array(
            array(Lexer::TYPE_GROUP => array(
                array(Lexer::TYPE_PARAMETER => 'x')
            ))
        );

        $output = array(Parser::TYPE_PARAMETER, 'x');

        $this->verifyOutput($input, $output);
    }

    public function testPathTokenPropertyDotProperty()
    {
        $input = array(
            array(Lexer::TYPE_PROPERTY => 'x'),
            array(Lexer::TYPE_OPERATOR => '.'),
            array(Lexer::TYPE_PROPERTY => 'y')
        );

        $output = array(Parser::TYPE_PATH,
            array(Parser::TYPE_PROPERTY, 'x'),
            array(Parser::TYPE_PROPERTY, 'y')
        );

        $this->verifyOutput($input, $output);
    }

    public function testPathTokenPropertyDotPropertyDotFunction()
    {
        $input = array(
            array(Lexer::TYPE_PROPERTY => 'x'),
            array(Lexer::TYPE_OPERATOR => '.'),
            array(Lexer::TYPE_PROPERTY => 'y'),
            array(Lexer::TYPE_OPERATOR => '.'),
            array(Lexer::TYPE_PROPERTY => 'z')
        );

        $output = array(Parser::TYPE_PATH,
            array(Parser::TYPE_PROPERTY, 'x'),
            array(Parser::TYPE_PROPERTY, 'y'),
            array(Parser::TYPE_PROPERTY, 'z')
        );

        $this->verifyOutput($input, $output);
    }

    public function testPathTokenPropertyDotGroup()
    {
        $input = array(
            array(Lexer::TYPE_PROPERTY => 'x'),
            array(Lexer::TYPE_OPERATOR => '.'),
            array(Lexer::TYPE_GROUP => array(
                array(Lexer::TYPE_PROPERTY => 'y'),
                array(Lexer::TYPE_OPERATOR => '.'),
                array(Lexer::TYPE_PROPERTY => 'z')
            ))
        );

        $output = array(Parser::TYPE_PATH,
            array(Parser::TYPE_PROPERTY, 'x'),
            array(Parser::TYPE_PROPERTY, 'y'),
            array(Parser::TYPE_PROPERTY, 'z')
        );

        $this->verifyOutput($input, $output);
    }

    public function testOperatorPrecedence()
    {
        $input = array(
            array(Lexer::TYPE_OPERATOR => 'not'),
            array(Lexer::TYPE_PROPERTY => 'a'),
            array(Lexer::TYPE_OPERATOR => '.'),
            array(Lexer::TYPE_PROPERTY => 'b'),
            array(Lexer::TYPE_OPERATOR => '<'),
            array(Lexer::TYPE_PROPERTY => 'c'),
            array(Lexer::TYPE_OPERATOR => '+'),
            array(Lexer::TYPE_PROPERTY => 'd'),
            array(Lexer::TYPE_OPERATOR => '*'),
            array(Lexer::TYPE_PROPERTY => 'e'),
            array(Lexer::TYPE_OPERATOR => 'or'),
            array(Lexer::TYPE_PROPERTY => 'f')
        );

        $output = array(Parser::TYPE_FUNCTION, 'or',
            array(Parser::TYPE_FUNCTION, 'not',
                array(Parser::TYPE_FUNCTION, 'less',
                    array(Parser::TYPE_PATH,
                        array(Parser::TYPE_PROPERTY, 'a'),
                        array(Parser::TYPE_PROPERTY, 'b')
                    ),
                    array(Parser::TYPE_FUNCTION, 'plus',
                        array(Parser::TYPE_PROPERTY, 'c'),
                        array(Parser::TYPE_FUNCTION, 'times',
                            array(Parser::TYPE_PROPERTY, 'd'),
                            array(Parser::TYPE_PROPERTY, 'e')
                        )
                    )
                )
            ),
            array(Parser::TYPE_PROPERTY, 'f')
        );

        $this->verifyOutput($input, $output);
    }

    private function verifyOutput($input, $expectedOutput)
    {
        $actualOutput = $this->parser->parse($input);

        $this->assertSame($expectedOutput, $actualOutput);
    }

    /*
    private function verifyException($input, $expectedData)
    {
        $expected = array(Parser::ERROR_UNEXPECTED_INPUT, $expectedData);

        try {
            $this->parser->parse($input);

            $actual = null;
        } catch (Exception $exception) {
            $actual = array($exception->getCode(), $exception->getData());
        }

        $this->assertSame($expected, $actual);
    }
    */
}
