<?php

namespace Datto\Cinnabari;

use Datto\Cinnabari\Translator;

class SemanticAnalyzer
{
    const TYPE_GET = 0;
    const TYPE_DELETE = 1;

    public static function getQueryType($translatedRequest)
    {
        $lastRequest =  end($translatedRequest);
        list($lastTokenType, $lastToken) = each($lastRequest);
        if ($lastTokenType === Translator::TYPE_FUNCTION) {
            if ($lastToken['function'] === 'delete') {
                return self::TYPE_DELETE;
            }
        }

        return self::TYPE_GET;
    }
}
