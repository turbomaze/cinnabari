<?php

namespace Datto\Cinnabari\Tests;

use Datto\Cinnabari\Lexer;
use PHPUnit_Framework_TestCase;

class LexerTest extends PHPUnit_Framework_TestCase
{
    /** @var Lexer */
    private $lexer;

    public function __construct()
    {
        parent::__construct();

        $this->lexer = new Lexer();
    }

    public function testInvalidInputNull()
    {
        $input = null;

        $output = null;

        $this->verify($input, $output);
    }

    public function testInvalidInputInteger()
    {
        $input = 5;

        $output = null;

        $this->verify($input, $output);
    }

    public function testInvalidInputEmptyString()
    {
        $input = '';

        $output = null;

        $this->verify($input, $output);
    }

    public function testValidParameter()
    {
        $input = ':_0aA';

        $output = array(
            array(Lexer::TYPE_PARAMETER => '_0aA')
        );

        $this->verify($input, $output);
    }

    public function testValidWhitespace()
    {
        $input = " \t \n :_0aA \n \t ";

        $output = array(
            array(Lexer::TYPE_PARAMETER => '_0aA')
        );

        $this->verify($input, $output);
    }

    public function testInvalidParameterIllegalCharacter()
    {
        $input = ':*';

        $output = null;

        $this->verify($input, $output);
    }

    public function testInvalidParameterIllegalSpace()
    {
        $input = ': *';

        $output = null;

        $this->verify($input, $output);
    }

    public function testValidProperty()
    {
        $input = '_';

        $output = array(
            array(Lexer::TYPE_PROPERTY => '_')
        );

        $this->verify($input, $output);
    }

    public function testInvalidProperty()
    {
        $input = '*';

        $output = null;

        $this->verify($input, $output);
    }

    public function testFunctionZeroArguments()
    {
        $input = 'f()';

        $output = array(
            array(Lexer::TYPE_FUNCTION => array('f'))
        );

        $this->verify($input, $output);
    }

    public function testFunctionOneValidArgument()
    {
        $input = 'f(:x)';

        $output = array(
            array(Lexer::TYPE_FUNCTION => array(
                'f',
                array(
                    array(Lexer::TYPE_PARAMETER => 'x')
                )
            )
            )
        );

        $this->verify($input, $output);
    }

    public function testFunctionOneInvalidArgument()
    {
        $input = 'f(*)';

        $output = null;

        $this->verify($input, $output);
    }

    public function testFunctionTwoValidArguments()
    {
        $input = 'f(:x, y)';

        $output = array(
            array(Lexer::TYPE_FUNCTION => array(
                'f',
                array(
                    array(Lexer::TYPE_PARAMETER => 'x')
                ),
                array(
                    array(Lexer::TYPE_PROPERTY => 'y')
                )
            )
            )
        );

        $this->verify($input, $output);
    }

    public function testFunctionOneValidArgumentOneInvalidArgument()
    {
        $input = 'f(:x, *)';

        $output = null;

        $this->verify($input, $output);
    }

    public function testFunctionMissingClosingParenthesis()
    {
        $input = 'f(';

        $output = null;

        $this->verify($input, $output);
    }

    public function testGroupEmptyBody()
    {
        $input = '()';

        $output = null;

        $this->verify($input, $output);
    }

    public function testGroupMissingClosingParenthesis()
    {
        $input = '(';

        $output = null;

        $this->verify($input, $output);
    }

    public function testGroupParameterBody()
    {
        $input = '(:x)';

        $output = array(
            array(Lexer::TYPE_GROUP => array(
                array(Lexer::TYPE_PARAMETER => 'x')
            )
            )
        );

        $this->verify($input, $output);
    }

    public function testObjectEmptyBody()
    {
        $input = '{}';

        $output = null;

        $this->verify($input, $output);
    }

    public function testObjectParameterValue()
    {
        $input = '{
            "x": :x
        }';

        $output = array(
            array(Lexer::TYPE_OBJECT => array(
                'x' => array(
                    array(Lexer::TYPE_PARAMETER => 'x')
                )
            )
            )
        );

        $this->verify($input, $output);
    }

    public function testObjectPropertyValue()
    {
        $input = '{
            "x": x
        }';

        $output = array(
            array(Lexer::TYPE_OBJECT => array(
                'x' => array(
                    array(Lexer::TYPE_PROPERTY => 'x')
                )
            )
            )
        );

        $this->verify($input, $output);
    }

    public function testObjectInvalidKey()
    {
        $input = '{
            6: x
        }';

        $output = null;

        $this->verify($input, $output);
    }

    public function testObjectMissingKeyValueSeparator()
    {
        $input = '{
            "x" x
        }';

        $output = null;

        $this->verify($input, $output);
    }

    public function testObjectInvalidProperty()
    {
        $input = '{
            "x": *
        }';

        $output = null;

        $this->verify($input, $output);
    }

    public function testObjectMissingClosingBrace()
    {
        $input = '{
            "x": x
        ';

        $output = null;

        $this->verify($input, $output);
    }

    public function testObjectPropertyValueParameterValue()
    {
        $input = '{
            "x": :x,
            "y": x
        }';

        $output = array(
            array(Lexer::TYPE_OBJECT => array(
                'x' => array(
                    array(Lexer::TYPE_PARAMETER => 'x')
                ),
                'y' => array(
                    array(Lexer::TYPE_PROPERTY => 'x')
                )
            )
            )
        );

        $this->verify($input, $output);
    }

    public function testObjectDuplicateKey()
    {
        $input = '{
            "x": :x,
            "x": x
        }';

        $output = array(
            array(Lexer::TYPE_OBJECT => array(
                'x' => array(
                    array(Lexer::TYPE_PROPERTY => 'x')
                )
            )
            )
        );

        $this->verify($input, $output);
    }

    public function testObjectMissingPropertySeparator()
    {
        $input = '{
            "x": :x
            "x": x
        }';

        $output = null;

        $this->verify($input, $output);
    }

    public function testObjectPropertyValueInvalidValue()
    {
        $input = '{
            "x": x,
            "y": *
        }';

        $output = null;

        $this->verify($input, $output);
    }

    public function testUnaryExpressionParameter()
    {
        $input = 'not :x';

        $output = array(
            array(Lexer::TYPE_OPERATOR => 'not'),
            array(Lexer::TYPE_PARAMETER => 'x')
        );

        $this->verify($input, $output);
    }

    public function testBinaryExpressionPropertyDotFunction()
    {
        $input = 'x.f()';

        $output = array(
            array(Lexer::TYPE_PROPERTY => 'x'),
            array(Lexer::TYPE_OPERATOR => '.'),
            array(Lexer::TYPE_FUNCTION => array('f'))
        );

        $this->verify($input, $output);
    }

    public function testBinaryExpressionExpressionPlusExpression()
    {
        $input = 'x.f() + (:c)';

        $output = array(
            array(Lexer::TYPE_PROPERTY => 'x'),
            array(Lexer::TYPE_OPERATOR => '.'),
            array(Lexer::TYPE_FUNCTION => array('f')),
            array(Lexer::TYPE_OPERATOR => '+'),
            array(Lexer::TYPE_GROUP => array(
                array(Lexer::TYPE_PARAMETER => 'c')
            )
            )
        );

        $this->verify($input, $output);
    }

    public function testBinaryExpressionOperators()
    {
        $input = 'a . b + c - d * e / f <= g < h != i = j >= k > l and m or n';

        $output = array(
            array(Lexer::TYPE_PROPERTY => 'a'),
            array(Lexer::TYPE_OPERATOR => '.'),
            array(Lexer::TYPE_PROPERTY => 'b'),
            array(Lexer::TYPE_OPERATOR => '+'),
            array(Lexer::TYPE_PROPERTY => 'c'),
            array(Lexer::TYPE_OPERATOR => '-'),
            array(Lexer::TYPE_PROPERTY => 'd'),
            array(Lexer::TYPE_OPERATOR => '*'),
            array(Lexer::TYPE_PROPERTY => 'e'),
            array(Lexer::TYPE_OPERATOR => '/'),
            array(Lexer::TYPE_PROPERTY => 'f'),
            array(Lexer::TYPE_OPERATOR => '<='),
            array(Lexer::TYPE_PROPERTY => 'g'),
            array(Lexer::TYPE_OPERATOR => '<'),
            array(Lexer::TYPE_PROPERTY => 'h'),
            array(Lexer::TYPE_OPERATOR => '!='),
            array(Lexer::TYPE_PROPERTY => 'i'),
            array(Lexer::TYPE_OPERATOR => '='),
            array(Lexer::TYPE_PROPERTY => 'j'),
            array(Lexer::TYPE_OPERATOR => '>='),
            array(Lexer::TYPE_PROPERTY => 'k'),
            array(Lexer::TYPE_OPERATOR => '>'),
            array(Lexer::TYPE_PROPERTY => 'l'),
            array(Lexer::TYPE_OPERATOR => 'and'),
            array(Lexer::TYPE_PROPERTY => 'm'),
            array(Lexer::TYPE_OPERATOR => 'or'),
            array(Lexer::TYPE_PROPERTY => 'n'),
        );

        $this->verify($input, $output);
    }

    private function verify($input, $expectedOutput)
    {
        $actualOutput = $this->lexer->tokenize($input);

        $this->assertSame($expectedOutput, $actualOutput);
    }
}
