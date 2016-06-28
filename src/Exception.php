<?php

namespace Datto\Cinnabari;

class Exception extends \Exception
{
    /** @var mixed */
    private $data;

    /**
     * @param int $code
     * @param mixed $data
     * @param string|null $message
     */
    public function __construct($code, $data = null, $message = null)
    {
        $formattedMessage = $code . " Error: " . $message;

        parent::__construct($formattedMessage, $code);

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
