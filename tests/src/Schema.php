<?php

namespace Datto\Cinnabari\Tests;

use Datto\Cinnabari;
use Datto\Cinnabari\Format\FormatValue;

class Schema implements Cinnabari\Schema
{
    /** @var array */
    private $settings;

    public function setRelease($release)
    {
        switch ($release) {
            case 'zabulus':
                $this->settings = array(
                    'properties' => array(
                        'Zabulus' => array(
                            'devices' => array('Device', '`device`')
                        ),
                        'Device' => array(
                            'id' => array(FormatValue::TYPE_INTEGER, '`deviceID`'),
                            'macAddress' => array(FormatValue::TYPE_STRING, 'IF(`mac` <=> \'\', NULL, LOWER(`mac`))'),
                            'resellerId' => array(FormatValue::TYPE_INTEGER, '`resellerID`')
                        )
                    ),
                    'unique' => array(
                        '`device`' => array('`deviceID`')
                    ),
                    'links' => array()
                );
                return true;

            default:
                return false;
        }
    }

    public function getDatabaseDefinition($property)
    {
        return $this->getDefinition('Zabulus', $property);
    }

    public function getDefinition($class, $property)
    {
        $definition = &$this->settings['properties'][$class][$property];

        return $definition;
    }

    public function getUnique($table)
    {
        $unique = &$this->settings['unique'][$table];

        return $unique;
    }
}
