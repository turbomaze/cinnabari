<?php
namespace Datto\Cinnabari;

use Datto\Cinnabari\Mysql\Select;
use Datto\Cinnabari\Format\Arguments;
use Datto\Cinnabari\Php\Output;
use Datto\Cinnabari\Mysql\Expression\Column;
use Datto\Cinnabari\Mysql\Expression\OperatorAnd;
use Datto\Cinnabari\Mysql\Expression\OperatorDivides;
use Datto\Cinnabari\Mysql\Expression\OperatorEqual;

class Translator
{
    const TYPE_PARAMETER = 1;
    const TYPE_FUNCTION = 3;
    const TYPE_OBJECT = 4;
    const TYPE_TABLE = 5;
    const TYPE_JOIN = 6;
    const TYPE_VALUE = 7;

    private $schema;
    private $tables;
    private $class;
    private $table;
    private $tableId;

    public function __construct($schema)
    {
        $this->schema = $schema; 
        $this->tables = array();
    }

    public function translate($tree)
    {
        $stack = array_reverse($tree);

        $list = null;

        while (count($stack) > 0) {
            $current = array_pop($stack);

            if ($list === null) {
                list($this->class, $path) = $this->getPropertyDefinition('Database', $current[1]);
                $list = array_pop($path);

                if (!isset($this->class, $path, $list)) {
                    return false;
                }

                list($table, $id, $hasZero) = $this->getListDefinition($list);

                echo json_encode(array(
                    'class' => $this->class,
                    'path' => $path,
                    'list' => $list,
                    'table' => $this->table
                )) . "\n\n";

                $this->table = $this->setTable($table);
                $this->tableId = $table;
                continue;
            } else {
                if ($current[0] === Parser::TYPE_PROPERTY) {
                    log('current', array($current, $this->class, $this->table));

                    $value = $this->getProperty($this->class, $this->table, $current[1], $output);
                    log('table id', array($this->tableId, $value));
                    if (isset($value)) {
                        list($column, ) = $this->getValueDefinition($this->tableId, $value);

                        log('column', $column);
                    }
                }

                if ($current[0] === parser::TYPE_FUNCTION) {
                    foreach (array_reverse($current[2]) as $argument) {
                        array_push($stack, $argument);
                    }
                }
            }
        }

        return null;
    }

    private function getProperty($class, $table, $property, &$output)
    {
        list($type, $path) = $this->getPropertyDefinition($class, $property);
        if (is_int($type)) {
            $value = array_pop($path);
        } else {
            $value = null;
            foreach ($path as $connection) {
                $tableAIdentifier = $this->getTable($table);
                $conn = $this->getConnectionDefinition($tableAIdentifier, $connection);
                list($tableBIdentifier, $expression, $id, $allowsZeroMatches, $allowsMultipleMatches) = $conn;

                $this->class = $type;
                $this->table = $this->setTable($tableBIdentifier);
                $this->tableId = $tableBIdentifier;
            }
        }
        
        if ($value !== null) {
            return $value;
        }
    }

    public function getConnectionDefinition($tableIdentifier, $connection)
    {
        // TODO: throw exception
        $definition = &$this->schema['connections'][$tableIdentifier][$connection];

        return $definition;
    }

    public function getExpression($class, $table, $token, &$output)
    {
        $type = $token[0];
        switch ($type) {
            case Parser::TYPE_PARAMETER:
                $parameter = $token[1];
                self::getParameter($parameter, $output);
                break;
            case Parser::TYPE_PROPERTY:
                $property = $token[1];
                $this->getProperty($class, $table, $property, $output);
                break;
            case Parser::TYPE_FUNCTION:
                $function = $token[1];
                $arguments = array_slice($token, 2);
                $this->getFunction($class, $table, $function, $arguments, $output);
                break;
            default: // Parser::TYPE_OBJECT:
                $object = $token[1];
                $this->getObject($class, $table, $object, $output);
                break;
        } 
    }

    public function getFunction($class, $table, $function, $arguments, &$output)
    {
        if ($function === 'filter') {
            $tokens = array();
            foreach ($arguments as $argument) {
                $this->getExpression($class, $table, $argument, $output);
            }
        }
        return null;
    }

    public function getObject($class, $table, $tokens, &$output)
    {
        return null;
    }

    private function getPropertyDefinition($class, $property)
    {
        $definition = &$this->schema['classes'][$class][$property];

        if ($definition === null) {
            throw self::exceptionUnknownProperty($class, $property);
        }
        $type = reset($definition);
        $path = array_slice($definition, 1);
        return array($type, $path);
    }

    private function getValueDefinition($tableIdentifier, $value)
    {
        // TODO: throw exception
        $definition = &$this->schema['values'][$tableIdentifier][$value];

        if ($definition === null) {
            return null;
        }

        return $definition;
    }

    public function setTable($name)
    {
        $countTables = count($this->tables);
        return self::insert($this->tables, $name);
    }

    public function getTable($id)
    {
        $name = array_search($id, $this->tables, true);

        if (!is_string($name)) {
            return null;
        }

        if (0 < $id) {
            list(, $name) = json_decode($name);
        }

        return $name;

    }

    public function getListDefinition($list)
    {
        // TODO: throw exception
        $definition = &$this->schema['lists'][$list];

        if ($definition === null) {
            return null;
        }

        // array($table, $expression, $hasZero)
        return $definition;
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

function log($t, $a)
{
    echo $t . " :: " . json_encode($a) . "\n\n";
}
