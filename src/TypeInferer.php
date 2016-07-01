<?php

namespace Datto\Cinnabari;

use Datto\Cinnabari\Format\Arguments;
use Datto\Cinnabari\Mysql\Expression\Column;
use Datto\Cinnabari\Mysql\Expression\OperatorBinary;
use Datto\Cinnabari\Mysql\Expression\OperatorNot;
use Datto\Cinnabari\Mysql\Expression\Parameter;
use Datto\Cinnabari\Mysql\Select;
use Datto\Cinnabari\Php\Output;

class TypeInferer
{
    private static $TYPE_ANY = array(
        Output::TYPE_NULL,
        Output::TYPE_BOOLEAN,
        Output::TYPE_INTEGER,
        Output::TYPE_FLOAT,
        Output::TYPE_STRING
    );

    private $symbolTable;

    private $FUNCTION_SIGNATURES;

    public function __construct($functionSignatures)
    {
      $this->FUNCTION_SIGNATURES = $functionSignatures;
    }

    public function getTypes($translation)
    {
        $this->symbolTable = array();

        foreach ($translation as $key => $token) {
          $this->getExpressionType($token);
        }

        return $translation;
    }

    public function getExpressionType($expression)
    {
        list($type, $value) = each($expression);

        switch ($type) {
            case Translator::TYPE_PARAMETER:
                return array_map(function($type) use ($value) {
                    return array('returnType' => $type, 'parameters' => array($value => array($type)));
                }, self::$TYPE_ANY);

            case Translator::TYPE_VALUE:
                return array(array('returnType' => $value['type'], 'parameters' => array()));

            case Translator::TYPE_OBJECT:
                $parameters = array();

                // 1. recurse on all the keys' values
                // 1a. accumulate parameter settings
                
                return array(array('returnType' => Output::TYPE_NULL, 'parameters' => $parameters));

            case Translator::TYPE_FUNCTION:
                $functionName = $value['function'];

                echo $functionName . "'s log\n\n";

                // 1. get the actual arguments
                $arguments = array_map(function($argument) { return end($argument); }, $value['arguments']);

                // 2. get the function signature
                $signatures = $this->FUNCTION_SIGNATURES[$functionName];

                // 3. recurse on all of the arguments to get their types
                $argumentsTypes = array_map(array($this, 'getExpressionType'), $arguments);

                // 4. each argument imposes restrictions on which function signatures are viable;
                //    identify which parameter settings result in which signatures, and keep
                //    track of which are still viable after having recursed

                // create an array that connects parameter settings to signature indices
                $viableSignatures = array_map(function($signature, $signatureIndex) use ($signatures) {
                    return array('index' => $signatureIndex, 'parameters' => array()); 
                }, $signatures, array_keys($signatures));

                // filter out viable signatures with the constraints imposed by each argument
                for ($argumentIndex = 0; $argumentIndex < count($argumentsTypes); $argumentIndex++) {
                    // an individual arguments's potential types
                    $argumentTypes = $argumentsTypes[$argumentIndex];

                    // for each "currently" valid signature
                    foreach ($viableSignatures as $viableSignatureIndex => &$viableSignature) {
                        if (array_key_exists('ignore', $viableSignature)) {
                            continue;
                        }

                        $signature = $signatures[$viableSignature['index']];

                        // remove it if it is no longer viable under the constraints of the current argument
                        $wasAddedAtLeastOnce = false;
                        foreach ($argumentTypes as $key => $argumentType) {
                            // for each potential avenue of viability, record the parameters' types
                            if ($signature['argumentTypes'][$argumentIndex] === $argumentType['returnType']) {
                                foreach ($viableSignature['parameters'] as $name => &$types) {
                                    if (array_key_exists($name, $argumentType['parameters'])) {
                                        $types = array_unique(array_merge($types, $argumentType['parameters'][$name]));
                                    }
                                }
                                foreach ($argumentType['parameters'] as $name => $types) {
                                    if (!array_key_exists($name, $viableSignature['parameters'])) {
                                        $viableSignature['parameters'][$name] = $types;
                                    }
                                }
                                $wasAddedAtLeastOnce = true; 

                    if ($functionName === 'less') {
                        echo json_encode($viableSignature) . " aa\n\n";
                    }

                            }
                        }

                        if (!$wasAddedAtLeastOnce) {
                            $viableSignature['ignore'] = true;
                        }
                    }
                }


                $viableSignatures = array_filter($viableSignatures, function($viableSignature) {
                    return !array_key_exists('ignore', $viableSignature);
                });
                $foo = array_map(function($viableSignature) use ($signatures) {
                    $signature = $signatures[$viableSignature['index']];
                    return array(
                        'returnType' => $signature['returnType'], 'parameters' => $viableSignature['parameters']
                    );
                }, $viableSignatures);

                echo $functionName . "'s return\n";
                echo json_encode($foo) . " JJ\n\n";

                return $foo;
        }

        return null;
    }

    private static function isParameter($input)
    {
        return is_object($input) && self::endsWith(get_class($input), 'Parameter');
    }

    // from http://stackoverflow.com/questions/834303/startswith-and-endswith-functions-in-php
    private static function endsWith($haystack, $needle) {
        return $needle === "" || (($temp = strlen($haystack) - strlen($needle)) >= 0 && strpos($haystack, $needle, $temp) !== false);
    }
}

