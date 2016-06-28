<?php

namespace Datto\Cinnabari\Php;

use Datto\Cinnabari\Php\Output;

class OutputList extends Output
{
    private $idAlias;
    private $allowsZeroMatches;
    private $allowsMultipleMatches;
    private $innerOutput;
    
    public function __construct($idAlias, $allowsZeroMatches, $allowsMultipleMatches, &$innerOutput)
    {
        $this->idAlias = $idAlias;
        $this->allowsZeroMatches = $allowsZeroMatches;
        $this->allowsMultipleMatches = $allowsMultipleMatches;
        $this->innerOutput = $innerOutput;
    }

    public function getPhp()
    {
        $php = $this->innerOutput->getPhp();

        list($input, $output) = self::split($php);

        $id = "\$row[{$this->idAlias}]";

        if ($this->allowsZeroMatches) {
            $input = self::indent("if (isset({$id})) {\n{$input}\n}");
        }

        if ($this->allowsMultipleMatches) {
            $input = str_replace(self::$output, self::$output . "[{$id}]", $input);
            $output = self::addReindex($output);
        }

        return self::merge($input, $output);
    }
}
