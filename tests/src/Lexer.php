<?php

namespace Datto\Cinnabari\Tests;

use Datto\Cinnabari\Exception;
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

    public function testNull()
    {
        $input = null;

        $exception = array(
            'code' => Lexer::ERROR_UNEXPECTED_INPUT,
            'data' => null
        );

        $this->verifyException($input, $exception);
    }

    public function testInteger()
    {
        $input = 5;

        $exception = array(
            'code' => Lexer::ERROR_UNEXPECTED_INPUT,
            'data' => null
        );

        $this->verifyException($input, $exception);
    }

    public function testEmptyString()
    {
        $input = '';

        $exception = array(
            'code' => Lexer::ERROR_UNEXPECTED_INPUT,
            'data' => 0
        );

        $this->verifyException($input, $exception);
    }

    public function testParameter()
    {
        $input = ':_0aA';

        $output = array(
            array(Lexer::TYPE_PARAMETER => '_0aA')
        );

        $this->verifyOutput($input, $output);
    }

    public function testWhitespace()
    {
        $input = " \t \n :_0aA \n \t ";

        $exception = array(
            'code' => Lexer::ERROR_UNEXPECTED_INPUT,
            'data' => 0
        );

        $this->verifyException($input, $exception);
    }

    public function testParameterInvalidCharacter()
    {
        $input = ':*';

        $exception = array(
            'code' => Lexer::ERROR_UNEXPECTED_INPUT,
            'data' => 0
        );

        $this->verifyException($input, $exception);
    }

    public function testParameterInvalidSpace()
    {
        $input = ': *';

        $exception = array(
            'code' => Lexer::ERROR_UNEXPECTED_INPUT,
            'data' => 0
        );

        $this->verifyException($input, $exception);
    }

    public function testProperty()
    {
        $input = '_';

        $output = array(
            array(Lexer::TYPE_PROPERTY => '_')
        );

        $this->verifyOutput($input, $output);
    }

    public function testPropertyInvalidCharacter()
    {
        $input = '*';

        $exception = array(
            'code' => Lexer::ERROR_UNEXPECTED_INPUT,
            'data' => 0
        );

        $this->verifyException($input, $exception);
    }

    public function testFunctionZeroArguments()
    {
        $input = 'f()';

        $output = array(
            array(Lexer::TYPE_FUNCTION => array('f'))
        );

        $this->verifyOutput($input, $output);
    }

    public function testFunctionOneArgument()
    {
        $input = 'f(:x)';

        $output = array(
            array(Lexer::TYPE_FUNCTION => array(
                'f',
                array(
                    array(Lexer::TYPE_PARAMETER => 'x')
                )
            ))
        );

        $this->verifyOutput($input, $output);
    }

    public function testFunctionOneInvalidArgument()
    {
        $input = 'f(*)';

        $exception = array(
            'code' => Lexer::ERROR_UNEXPECTED_INPUT,
            'data' => 2
        );

        $this->verifyException($input, $exception);
    }

    public function testFunctionTwoArguments()
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
            ))
        );

        $this->verifyOutput($input, $output);
    }

    public function testFunctionOneValidArgumentOneInvalidArgument()
    {
        $input = 'f(:x, *)';

        $exception = array(
            'code' => Lexer::ERROR_UNEXPECTED_INPUT,
            'data' => 6
        );

        $this->verifyException($input, $exception);
    }

    public function testFunctionMissingClosingParenthesis()
    {
        $input = 'f(';

        $exception = array(
            'code' => Lexer::ERROR_UNEXPECTED_INPUT,
            'data' => 2
        );

        $this->verifyException($input, $exception);
    }

    public function testGroupEmptyBody()
    {
        $input = '()';

        $exception = array(
            'code' => Lexer::ERROR_UNEXPECTED_INPUT,
            'data' => 1
        );

        $this->verifyException($input, $exception);
    }

    public function testGroupMissingClosingParenthesis()
    {
        $input = '(';

        $exception = array(
            'code' => Lexer::ERROR_UNEXPECTED_INPUT,
            'data' => 1
        );

        $this->verifyException($input, $exception);
    }

    public function testGroupParameterBody()
    {
        $input = '(:x)';

        $output = array(
            array(Lexer::TYPE_GROUP => array(
                array(Lexer::TYPE_PARAMETER => 'x')
            ))
        );

        $this->verifyOutput($input, $output);
    }

    public function testObjectEmptyBody()
    {
        $input = '{}';

        $exception = array(
            'code' => Lexer::ERROR_UNEXPECTED_INPUT,
            'data' => 1
        );

        $this->verifyException($input, $exception);
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
            ))
        );

        $this->verifyOutput($input, $output);
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
            ))
        );

        $this->verifyOutput($input, $output);
    }

    public function testObjectInvalidKey()
    {
        $input = '{
            6: x
        }';

        $exception = array(
            'code' => Lexer::ERROR_UNEXPECTED_INPUT,
            'data' => 5
        );

        $this->verifyException($input, $exception);
    }

    public function testObjectMissingKeyValueSeparator()
    {
        $input = '{
            "x" x
        }';

        $exception = array(
            'code' => Lexer::ERROR_UNEXPECTED_INPUT,
            'data' => 8
        );

        $this->verifyException($input, $exception);
    }

    public function testObjectInvalidProperty()
    {
        $input = '{
            "x": *
        }';

        $exception = array(
            'code' => Lexer::ERROR_UNEXPECTED_INPUT,
            'data' => 10
        );

        $this->verifyException($input, $exception);
    }

    public function testObjectMissingClosingBrace()
    {
        $input = '{
            "x": x';

        $exception = array(
            'code' => Lexer::ERROR_UNEXPECTED_INPUT,
            'data' => 11
        );

        $this->verifyException($input, $exception);
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
            ))
        );

        $this->verifyOutput($input, $output);
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
            ))
        );

        $this->verifyOutput($input, $output);
    }

    public function testObjectMissingPropertySeparator()
    {
        $input = '{
            "x": :x
            "x": x
        }';

        $exception = array(
            'code' => Lexer::ERROR_UNEXPECTED_INPUT,
            'data' => 12
        );

        $this->verifyException($input, $exception);
    }

    public function testObjectPropertyValueInvalidValue()
    {
        $input = '{
            "x": x,
            "y": *
        }';

        $exception = array(
            'code' => Lexer::ERROR_UNEXPECTED_INPUT,
            'data' => 21
        );

        $this->verifyException($input, $exception);
    }

    public function testUnaryExpressionParameter()
    {
        $input = 'not :x';

        $output = array(
            array(Lexer::TYPE_OPERATOR => 'not'),
            array(Lexer::TYPE_PARAMETER => 'x')
        );

        $this->verifyOutput($input, $output);
    }

    public function testBinaryExpressionPropertyDotFunction()
    {
        $input = 'x.f()';

        $output = array(
            array(Lexer::TYPE_PROPERTY => 'x'),
            array(Lexer::TYPE_OPERATOR => '.'),
            array(Lexer::TYPE_FUNCTION => array('f'))
        );

        $this->verifyOutput($input, $output);
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
            ))
        );

        $this->verifyOutput($input, $output);
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

        $this->verifyOutput($input, $output);
    }

    private function verifyOutput($input, $expectedOutput)
    {
        $actualOutput = $this->lexer->tokenize($input);

        $this->assertSame($expectedOutput, $actualOutput);
    }

    private function verifyException($input, $expected)
    {
        try {
            $this->lexer->tokenize($input);

            $actual = null;
        } catch (Exception $exception) {
            $actual = array(
                'code' => $exception->getCode(),
                'data' => $exception->getData()
            );
        }

        $this->assertSame($expected, $actual);
    }
}
