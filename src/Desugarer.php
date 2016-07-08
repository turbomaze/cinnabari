<?php

namespace Datto\Cinnabari;

class Desugarer
{
    private $sprinkles;

    public function __construct()
    {
        $this->sprinkles = array(
            // allow users to enter objects in updates() instead of just paired-arrays
            array($this, 'desugarUpdateObject')
        );
    }

    public function desugar($request)
    {
        foreach ($this->sprinkles as $key => $sprinkle) {
            $request = call_user_func($sprinkle, $request);
        } 

        return $request;
    }

    private function desugarUpdateObject($request)
    {
        $updateFunctionName = 'update';
        foreach ($request as $key => &$token) {
            if ($token[0] === Parser::TYPE_FUNCTION) {
                $functionName = $token[1];

                if ($functionName === $updateFunctionName && count($token) === 3) { // exactly one argument
                    $argument = array_pop($token)[0]; // first argument
                    if ($argument[0] === Parser::TYPE_OBJECT) {
                        $arrays = $this->unzipObjectTokenToArrays($argument);
                        $token = array_merge($token, $arrays);
                    } else {
                        $token[] = array($argument);
                    }
                }
            }
        }

        return $request;
    }

    private function unzipObjectTokenToArrays($objectToken) {
        $object = $objectToken[1];
        $arrays = array(
            array(array(Parser::TYPE_ARRAY, array())),
            array(array(Parser::TYPE_ARRAY, array()))
        );

        foreach ($object as $property => $value) {
           $arrays[0][0][1][] = $this->getPropertyTokensFromString($property);
           $arrays[1][0][1][] = $value;
        }

        return $arrays;
    }

    private function getPropertyTokensFromString($property) {
        return array_map(function($component) {
            return array(Parser::TYPE_PROPERTY, $component); 
        }, explode('.', $property));
    }
}
