<?php

namespace Datto\Cinnabari;

/**
 * Class StatelessCompiler
 * 
 * @package Datto\Cinnabari
 *
 * EBNF:
 *
 * request = list, [ filter-function ], map-function
 * map-argument = path | property | object | map
 * object-value = path | property | object | map
 */

class StatelessCompiler
{
    const PROPERTY_TYPE = 2;
    const OBJECT_TYPE = 2;

    private static $OP_MAP = [
        'equal' => '<=>',
        'or' => 'OR',
        'and' => 'AND'
    ];

    public static function compile($schema, $request, $arguments)
    {
        array_shift($request); // assume it starts with a path token

        $mysql = self::getSQL($schema, $request, $params);

        $formatParams = self::getQueryParams($params);

        $propLocs = [];

        $phpOutput = self::getPhp($schema, $request, $propLocs);

        return array($mysql, $formatParams, $phpOutput);
    }

    public static function getMapData($schema, $request)
    {
        $className = $schema['classes']['Database'][$request[0][1]][0];

        return [$className => ['`Id`', '`Name`']];
    }

    public static function getPhp($schema, $request, $propLocations)
    {
        // need the classname to trace properties around
        $className = $schema['classes']['Database'][$request[0][1]][0];
        $listName = $schema['classes']['Database'][$request[0][1]][1];
        $tableName = $schema['lists'][$listName][0];
        $colNames = $schema['values'][$tableName];
        $fields = [];
        $mapTree = $request[2][2];

        if ($mapTree[0] === self::PROPERTY_TYPE) {
            // map to a simple property
            $propName = $mapTree[1];
            $colName = $schema['classes'][$className][$propName][1];
            $fmtColName = $schema['values'][$tableName][$colName][0];

            $fields[$mapTree[1]] = $propLocations[$className][$fmtColName];
        } else if ($mapTree[0] === self::OBJECT_TYPE) {
            // map to a custom object
            $obj = $mapTree[1];
            foreach ($obj as $key => $value) {
                $fields[$key] = $value;
            }
        }

        $phpOutput = "foreach (\$input as \$row) {\n";

        foreach ($fields as $field => $value) {
            $phpOutput .= "\t\$output[\$row[0]]['";
            $phpOutput .= $field . "']";
            $phpOutput .= " = \$row[$value];\n";
        }

        $phpOutput .= "}\n\n";
        $phpOutput .= "\$output = isset(\$output) ? ";
        $phpOutput .= "array_values(\$output) : array();";


        return $phpOutput;
    }

    public static function getQueryParams($params)
    {
        $fmtParams = "\$params = array(";
        foreach ($params as $param) {
            $fmtParams .= "\n\t\$input['" . $param .  "'],";
        }
        $fmtParams .= "\n);";
        return $fmtParams;
    }

    public static function getSQL($schema, $request, &$params)
    {
        $mysql = "SELECT\n\t";
        $mysql .= self::getSQLColumns($schema, $request);
        $mysql .= "\n\tFROM ";
        $mysql .= self::getSQLTable($schema, $request);

        if ($request[1][0] === 3) {
            $mysql .= "\n\tWHERE ";
            $mysql .= self::getSQLConditional($schema, $request, $params);
        }

        return $mysql;
    }

    public static function getSQLColumns($schema, $request)
    {
        // always get the primary key
        $listName = $schema['classes']['Database'][$request[0][1]][1];
        $columns  = [$schema['lists'][$listName][1]];

        // get other columns

        // format them
        for ($i = 0; $i < count($columns); $i++) {
            $columns[$i] = $columns[$i] . " AS `$i`";
        }

        return implode(",\n\t", $columns);
    }

    public static function getSQLTable($schema, $request)
    {
        $listName = $schema['classes']['Database'][$request[0][1]][1];
        $tableName = $schema['lists'][$listName][0];
        return $tableName;
    }

    public static function getSQLConditional($schema, $request, &$params)
    {
        $className = $schema['classes']['Database'][$request[0][1]][0];
        $listName = $schema['classes']['Database'][$request[0][1]][1];
        $tableName = $schema['lists'][$listName][0];

        $props = $schema['classes'][$className];
        $values = $schema['values'][$tableName];
        $expressionTree = $request[1][2];

        return self::getStrFromExpr($props, $values, $expressionTree, $params);
    }

    public static function getStrFromExpr($props, $values, $tree, &$params=[])
    {
        if ($tree[0] === 1) {
            // base case 1: parameter
            $params[] = $tree[1];
            return ':' . (count($params)-1);
        } else if ($tree[0] === 2) {
            // base case 2: property
            $prop = $props[$tree[1]][1];
            return $values[$prop][0];
        } else if ($tree[0] === 3) {
            // recursion: (, left, operator, right, )
            $op = self::$OP_MAP[$tree[1]];
            $left = self::getStrFromExpr($props, $values, $tree[2], $params);
            $right = self::getStrFromExpr($props, $values, $tree[3], $params);
            return '(' . $left . ' ' . $op . ' ' . $right . ')';
        } else {
            // bad
            return false;
        }
    }
}
