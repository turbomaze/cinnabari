<?php

namespace Datto\Cinnabari\Php;

class Output
{
    const TYPE_NULL = 0;
    const TYPE_BOOLEAN = 1;
    const TYPE_INTEGER = 2;
    const TYPE_FLOAT = 3;
    const TYPE_STRING = 4;
    const TYPE_ARRAY = 5;

    private static $output = '$output';

    /**
     * @param int $index
     * @param int $type
     * @param bool $isNullable
     * @return string
     */
    public static function getValue($index, $isNullable, $type)
    {
        $value = "\$row[{$index}]";
        $cast = self::getTypeCast($type);

        $expression = "{$cast}{$value}";

        if ($isNullable && ($type !== self::TYPE_STRING)) {
            $expression = "isset({$value}) ? {$expression} : null";
        }

        $input = self::$output . " = {$expression};";
        $output = '';

        return self::merge("\t{$input}", $output);
    }

    /**
     * @param string[] $properties
     * @return string
     */
    public static function getObject($properties)
    {
        $input = '';
        $output = '';

        foreach ($properties as $name => $php) {
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

    /**
     * @param int $index
     * @param bool $hasZero
     * @param bool $hasMany
     * @param string $php
     * @return string
     */
    public static function getList($index, $hasZero, $hasMany, $php)
    {
        list($input, $output) = self::split($php);

        $id = "\$row[{$index}]";

        if ($hasZero) {
            $input = self::indent("if (isset({$id})) {\n{$input}\n}");
        }

        if ($hasMany) {
            $input = str_replace(self::$output, self::$output . "[{$id}]", $input);
            $output = self::addReindex($output);
        }

        return self::merge($input, $output);
    }

    private static function getTypeCast($type)
    {
        switch ($type) {
            case self::TYPE_BOOLEAN:
                return '(boolean)';

            case self::TYPE_INTEGER:
                return '(integer)';

            case self::TYPE_FLOAT:
                return '(float)';

            case self::TYPE_STRING:
                return '';

            default:
                return null;
        }
    }

    private static function addReindex($php)
    {
        if (0 < strlen($php)) {
            $currentIndex = self::getIndex($php);

            if ($currentIndex === null) {
                $nextIndex = 0;
            } else {
                $nextIndex = $currentIndex + 1;
            }

            $variable = "\$x{$nextIndex}";

            $php = self::indent(str_replace(self::$output, $variable, $php));
            $php = "\n\nforeach (\$output as &{$variable}) {\n{$php}\n}";
        }

        return "\$output = isset(\$output) ? array_values(\$output) : array();{$php}";
    }

    private static function getIndex($text)
    {
        if (preg_match('~&\$x([0-9]+)~', $text, $match) === 1) {
            return (integer)$match[1];
        }

        return null;
    }

    private static function split($php)
    {
        $delimiter = '~';
        $foreach = preg_quote('foreach ($input as $row) {', $delimiter);
        $pattern = "{$delimiter}{$foreach}\n(.*?)\n}\\s*(.*){$delimiter}s";

        preg_match($pattern, $php, $matches);
        array_shift($matches);

        return $matches;
    }

    private static function merge($input, $output)
    {
        $php = "foreach (\$input as \$row) {\n{$input}\n}";

        if (0 < strlen($output)) {
            $php .= "\n\n{$output}";
        }

        return $php;
    }

    private static function indent($string)
    {
        return "\t" . preg_replace('~\n(?!\n)~', "\n\t", $string);
    }
}
