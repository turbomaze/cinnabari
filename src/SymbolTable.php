<?php

namespace Datto\Cinnabari;

use Datto\Cinnabari\Php\Output;

class SymbolTable
{
    const JOIN_INNER = 1;
    const JOIN_LEFT = 2;

    private $symbols;
    private $symbolLookup;
    private $joins;

    public function __construct(Schema $schema)
    {
        $this->schema = $schema;
        $this->symbols = array(); // keep track of the symbols
        $this->symbolLookup = array(); // helps avoid duplicates
        $this->joins = array(); // stores the join information
    }

    public function getSymbols($tree)
    {
        // first element in a Datto API tree is the context
        $context = $tree[1][1];

        // handle the first connection
        $this->enterDatabase($class, $tableName, $context);

        // get rid of the first two components of the outermost path token
        $treeSuffix = array_slice($tree, 2);

        // traverse the tree (dfs) and keep track of the symbols
        $output = array();
        foreach ($treeSuffix as $node) {
            $output[] = $this->traverse($class, $tableName, $node);
        }

        // add in the preamble; entry table name and the joins
        $preamble = array(array(Parser::TYPE_TABLE, $tableName, 0)); // initial table token
        foreach ($this->joins as $join => $joinId) {
            $symbolId = count($this->symbols);
            $preamble[] = array(Parser::TYPE_JOIN, $symbolId);
            $this->symbols[] = $join;
        }

        return array($this->symbols, $preamble, $output);
    }

    private function enterDatabase(&$class, &$tableName, $context)
    {
        list($class, $path) = $this->schema->getPropertyDefinition('Database', $context);
        $list = array_pop($path);
        list($tableName, $id, $hasZero) = $this->schema->getListDefinition($list);
        $this->connections($tables, $startTable, $tableName, $path);
        $table = count($tables);
        $this->symbols[] = "`{$table}`.{$id}"; // this is a special symbol
    }

    private function traverse($class, $tableName, $node)
    {
        list($type, $value, ) = $node;

        switch ($type) {
            // terminal values: narameters, paths, and properties
            case Parser::TYPE_PARAMETER:
                $symbolId = count($this->symbols);
                $this->symbols[] = array(':' . $value);
                $this->symbolLookup[':' . $value] = $symbolId;
                return array(Parser::TYPE_PARAMETER, $symbolId);

            case Parser::TYPE_PATH:
                $path = array_map(function ($elem) {
                    return $elem[1];
                }, array_slice($node, 1));
                // fall through and continue; this just sets the path

            case Parser::TYPE_PROPERTY:
                if ($type === Parser::TYPE_PROPERTY) {
                    $path = array($value);
                }

                // make sure this property isn't already in the symbol table
                $pathKey = json_encode($path);
                if (array_key_exists($pathKey, $this->symbolLookup)) {
                    return array(Parser::TYPE_VALUE, $this->symbolLookup[$pathKey]);
                }

                // get the property's MySQL and add it to the symbol table
                $symbolId = count($this->symbols);
                $this->symbols[] = $this->getMySQLIdentifier($class, $tableName, $path, $newJoins);
                $this->joins = array_merge($this->joins, $newJoins);
                $this->symbolLookup[$pathKey] = $symbolId;
                return array(Parser::TYPE_VALUE, $symbolId);

            // recurse on objects
            case Parser::TYPE_OBJECT:
                $newTree = array();
                foreach ($value as $key => $child) {
                    $newTree[$key] = $this->traverse($class, $tableName, $child);
                }
                return array(Parser::TYPE_OBJECT, $newTree);

            // recurse on functions
            case Parser::TYPE_FUNCTION:
                $newTree = array_slice($node, 0, 2);
                
                foreach (array_slice($node, 2) as $child) {
                    $newTree[] = $this->traverse($class, $tableName, $child);
                }
                return $newTree;
        }
        return $node;
    }

    private function getMySQLIdentifier($class, $tableName, $apiPath, &$joins)
    {
        // initialize the table and class with the first connection
        $tables = array($tableName => 0);
        $table = self::insert($tables, $tableName);

        // handle the connections of all but the final element
        for ($i = 0; $i < count($apiPath)-1; $i++) {
            $waypoint = $apiPath[$i];
            list($class, $connections) = $this->schema->getPropertyDefinition($class, $waypoint);

            $this->connections($tables, $table, $tableName, $connections);
        }

        // handle the final element separately to get its value
        list($type, $path) = $this->schema->getPropertyDefinition($class, $apiPath[count($apiPath)-1]);
        $value = array_pop($path);
        $this->connections($tables, $table, $tableName, $path);
        $tableName = $this->getTable($tables, $table);
        list($column, $isColumnNullable) = $this->schema->getValueDefinition($tableName, $value);

        // add the joins
        $joins = array_slice($tables, 1);

        // return the annotation
        return array("`{$table}`.{$column}", $type);
    }

    private function connections(&$tables, &$contextId, &$tableAIdentifier, $connections)
    {
        foreach ($connections as $key => $connection) {
            $definition = $this->schema->getConnectionDefinition($tableAIdentifier, $connection);

            list($tableBIdentifier, $expression, $id, $allowsZeroMatches, $allowsMultipleMatches) = $definition;

            if (!$allowsZeroMatches && !$allowsMultipleMatches) {
                $joinType = self::JOIN_INNER; // inner
            } else {
                $joinType = self::JOIN_LEFT; // left
            }

            $contextId = $this->addJoin($tables, $contextId, $tableBIdentifier, $expression, $joinType);
            $tableAIdentifier = $tableBIdentifier;
        }
    }

    private function addJoin(&$tables, $tableAId, $tableBIdentifier, $mysqlExpression, $joinType)
    {
        $tableIdentifierA = "`{$tableAId}`";
        $key = json_encode(array($tableIdentifierA, $tableBIdentifier, $mysqlExpression, $joinType));
        return self::insert($tables, $key);
    }

    public function getTable($tables, $id)
    {
        $name = array_search($id, $tables, true);

        if (!is_string($name)) {
            return null;
        }

        if (0 < $id) {
            list(, $name) = json_decode($name);
        }

        return $name;
    }

    private static function insert(&$array, $key)
    {
        $id = &$array[$key];
        if (!isset($id)) {
            $id = count($array) - 1;
        }
        return $id;
    }
}
