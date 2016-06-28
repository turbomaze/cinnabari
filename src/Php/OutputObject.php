<?php

namespace Datto\Cinnabari\Php;

use Datto\Cinnabari\Php\Output;

class OutputObject extends Output
{
    private $properties;
    
    public function __construct(&$properties)
    {
        $this->properties = $properties;
    }

    public function getPhp()
    {
        $input = '';
        $output = '';

        foreach ($this->properties as $name => $outputObject) {
            $php = $outputObject->getPhp();
            $key = var_export($name, true);
            $php = str_replace(self::$output, self::$output . "[{$key}]", $php);

            list($inputProperty, $outputProperty) = self::split($php);

            if (substr(trim($inputProperty), 0, 3) === 'if ') {
                $input .= "\n\n";
            } else {
                $input .= "\n";
            }

            $input .= $inputProperty;

            if (0 < strlen($outputProperty)) {
                $output .= "\n";
            }

            $output .= $outputProperty;
        }

        $input = trim($input, "\n");
        $output = trim($output, "\n");

        return self::merge($input, $output);
    }
}
