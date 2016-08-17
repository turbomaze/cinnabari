<?php

error_reporting(E_ALL | E_STRICT);
ini_set('display_errors', 1);

spl_autoload_register(
    function ($class)
    {
        $rootDirectory = dirname(__DIR__);

        $translations = array(
            'Datto\Cinnabari' => 'src',
            'Datto\Cinnabari\Tests' => 'tests/src',
            'Datto\PhpTypeInferer' => 'src'
        );

        foreach ($translations as $namespace => $projectDirectory) {
            $namespacePrefix = $namespace . '\\';
            $namespacePrefixLength = strlen($namespacePrefix);

            if (strncmp($class, $namespacePrefix, $namespacePrefixLength) !== 0) {
                continue;
            }

            $relativeClassName = substr($class, $namespacePrefixLength);
            $relativeFilePath = strtr($relativeClassName, '\\', '/') . '.php';
            $absoluteFilePath = "{$rootDirectory}/{$projectDirectory}/{$relativeFilePath}";

            if (is_file($absoluteFilePath)) {
                include $absoluteFilePath;
                return;
            }
        }
    }
);
