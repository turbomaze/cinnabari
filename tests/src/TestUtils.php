<?php

namespace Datto\Cinnabari\Tests;

class TestUtils
{
    // strips the whitespace from php source code
    public static function removePHPWhitespace($str)
    {
        // NOTE: doesn't work for contrived examples with space-sensitive string literals
        // built-in php_strip_whitespace() only operates on files
        return preg_replace('/\s+/', ' ', $str);
    }

    // strips the whitespace from mysql queries
    public static function removeMySQLWhitespace($str)
    {
        // NOTE: also behaves improperly if the input contains space-sensitive strings
        return preg_replace('/\s+/', ' ', $str);
    }
}
