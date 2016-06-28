<?php

namespace Datto\Cinnabari\Php;

class Output
{
    const TYPE_NULL = 0;
    const TYPE_BOOLEAN = 1;
    const TYPE_INTEGER = 2;
    const TYPE_FLOAT = 3;
    const TYPE_STRING = 4;

    protected static $output = '$output';

    protected static function getTypeCast($type)
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

    protected static function addReindex($php)
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

    protected static function getIndex($text)
    {
        if (preg_match('~&\$x([0-9]+)~', $text, $match) === 1) {
            return (integer)$match[1];
        }

        return null;
    }

    protected static function split($php)
    {
        $delimiter = '~';
        $foreach = preg_quote('foreach ($input as $row) {', $delimiter);
        $pattern = "{$delimiter}{$foreach}\n(.*?)\n}\\s*(.*){$delimiter}s";

        preg_match($pattern, $php, $matches);
        array_shift($matches);

        return $matches;
    }

    protected static function merge($input, $output)
    {
        $php = "foreach (\$input as \$row) {\n{$input}\n}";

        if (0 < strlen($output)) {
            $php .= "\n\n{$output}";
        }

        return $php;
    }

    protected static function indent($string)
    {
        return "\t" . preg_replace('~\n(?!\n)~', "\n\t", $string);
    }
}
