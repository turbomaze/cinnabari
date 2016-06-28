<?php

namespace Datto\Cinnabari\Php;

use Datto\Cinnabari\Php\Output;

class OutputValue extends Output
{
    private $columnId;
    private $isNullable;
    private $datatype;

    public function __construct($columnId, $isNullable, $datatype)
    {
        $this->columnId = $columnId;
        $this->isNullable = $isNullable;
        $this->datatype = $datatype;
    }

    public function getPhp()
    {
        $value = "\$row[{$this->columnId}]";
        $cast = self::getTypeCast($this->datatype);

        $expression = "{$cast}{$value}";

        if ($this->isNullable && ($this->datatype !== self::TYPE_STRING)) {
            $expression = "isset({$value}) ? {$expression} : null";
        }

        $input = self::$output . " = {$expression};";
        $output = '';

        return self::merge("\t{$input}", $output);
    }
}
