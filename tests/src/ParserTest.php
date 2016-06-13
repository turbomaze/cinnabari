<?php

namespace Datto\Cinnabari\Tests;

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

    public function testParameterToken()
    {
        $input = array(
            array(Lexer::TYPE_PARAMETER => 'x')
        );

        $output = array(array(Parser::TYPE_PARAMETER, 'x'));

        $this->verify($input, $output);
    }

    public function testPropertyToken()
    {
        $input = array(
            array(Lexer::TYPE_PROPERTY => 'x')
        );

        $output = array(array(Parser::TYPE_PROPERTY, 'x'));

        $this->verify($input, $output);
    }

    public function testFunctionTokenNoArguments()
    {
        $input = array(
            array(Lexer::TYPE_FUNCTION => array('f'))
        );

        $output = array(array(Parser::TYPE_FUNCTION, 'f'));

        $this->verify($input, $output);
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

        $output = array(array(Parser::TYPE_FUNCTION, 'f',
            array(array(Parser::TYPE_PARAMETER, 'x'))
        ));

        $this->verify($input, $output);
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

        $output = array(array(Parser::TYPE_FUNCTION, 'f',
            array(array(Parser::TYPE_PARAMETER, 'x')),
            array(array(Parser::TYPE_PROPERTY, 'y'))
        ));

        $this->verify($input, $output);
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

        $output = array(array(Parser::TYPE_OBJECT, array(
            'a' => array(array(Parser::TYPE_PARAMETER, 'x'))
        )));

        $this->verify($input, $output);
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

        $output = array(array(Parser::TYPE_OBJECT, array(
            'a' => array(array(Parser::TYPE_PARAMETER, 'x')),
            'b' => array(array(Parser::TYPE_PROPERTY, 'y'))
        )));

        $this->verify($input, $output);
    }

    public function testGroupToken()
    {
        $input = array(
            array(Lexer::TYPE_GROUP => array(
                array(Lexer::TYPE_PARAMETER => 'x')
            ))
        );

        $output = array(array(Parser::TYPE_PARAMETER, 'x'));

        $this->verify($input, $output);
    }

    public function testPathTokenPropertyDotProperty()
    {
        $input = array(
            array(Lexer::TYPE_PROPERTY => 'x'),
            array(Lexer::TYPE_OPERATOR => '.'),
            array(Lexer::TYPE_PROPERTY => 'y')
        );

        $output = array(
            array(Parser::TYPE_PROPERTY, 'x'),
            array(Parser::TYPE_PROPERTY, 'y')
        );

        $this->verify($input, $output);
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

        $output = array(
            array(Parser::TYPE_PROPERTY, 'x'),
            array(Parser::TYPE_PROPERTY, 'y'),
            array(Parser::TYPE_PROPERTY, 'z')
        );

        $this->verify($input, $output);
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

        $output = array(
            array(Parser::TYPE_PROPERTY, 'x'),
            array(Parser::TYPE_PROPERTY, 'y'),
            array(Parser::TYPE_PROPERTY, 'z')
        );

        $this->verify($input, $output);
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

        $output = array(
            array(Parser::TYPE_FUNCTION, 'or',
                array(array(Parser::TYPE_FUNCTION, 'not',
                    array(array(Parser::TYPE_FUNCTION, 'less',
                        array(
                            array(Parser::TYPE_PROPERTY, 'a'),
                            array(Parser::TYPE_PROPERTY, 'b')
                        ),
                        array(array(Parser::TYPE_FUNCTION, 'plus',
                            array(array(Parser::TYPE_PROPERTY, 'c')),
                            array(array(Parser::TYPE_FUNCTION, 'times',
                                array(array(Parser::TYPE_PROPERTY, 'd')),
                                array(array(Parser::TYPE_PROPERTY, 'e'))
                            )))
                        ))
                    ))
                ),
                array(array(Parser::TYPE_PROPERTY, 'f'))
            )
        );

        $this->verify($input, $output);
    }

    private function verify($input, $expectedOutput)
    {
        $actualOutput = $this->parser->parse($input);

        $this->assertSame($expectedOutput, $actualOutput);
    }
}
