<?php

namespace Datto\Cinnabari;

class Exception extends \Exception
{
    // schema errors
    const ERROR_CLASS_DNE = 101;
    const ERROR_PROPERTY_DNE = 102;
    const ERROR_LIST_DNE = 103;
    const ERROR_VALUE_DNE = 104;
    const ERROR_CONNECTION_DNE = 105;

    // mysql errors
    const ERROR_BAD_TABLE_ID = 201;

    // arguments errors
    const ERROR_WRONG_INPUT_TYPE = 301;
    const ERROR_INPUT_NOT_PROVIDED = 302;

    // php output errors
    const ERROR_UNKNOWN_TYPECAST = 401;

    // compiler errors
    const ERROR_NO_INITIAL_PROPERTY = 501;
    const ERROR_NO_MAP_FUNCTION = 502;
    const ERROR_NO_FILTER_ARGUMENTS = 503;
    const ERROR_BAD_FILTER_EXPRESSION = 504;
    const ERROR_NO_SORT_ARGUMENTS = 505;
    const ERROR_BAD_MAP_ARGUMENT = 506;

    /** @var mixed */
    private $data;

    /**
     * @param int $code
     * @param mixed $data
     * @param string|null $message
     */
    public function __construct($code, $data = null, $message = null)
    {
        parent::__construct($message, $code);

        $this->data = $data;
    }

    /**
     * @return mixed
     */
    public function getData()
    {
        return $this->data;
    }
}
