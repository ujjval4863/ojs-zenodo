<?php

namespace APP\plugins\generic\zenodo;

class ZenodoApiException extends \RuntimeException
{
    public function __construct(string $message, private int $httpStatus = 0)
    {
        parent::__construct($message, $httpStatus);
    }

    public function getHttpStatus(): int
    {
        return $this->httpStatus;
    }
}
