<?php

namespace Datto\Cinnabari;

class Exception extends \Exception
{
    const ERROR_CLASS_DNE = 101;
    const ERROR_LIST_DNE = 102;
    const ERROR_VALUE_DNE = 103;
    const ERROR_CONNECTION_DNE = 104;
    const ERROR_BAD_TABLE_ID = 201;
    const ERROR_WRONG_INPUT_TYPE = 301;
    const ERROR_INPUT_NOT_PROVIDED = 302;
    const ERROR_UNKNOWN_TYPECAST = 401;

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
