<?php

namespace App\Exceptions\Messaging;

use Exception;

class LegacyApiValidationException extends Exception
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $httpStatus = 400,
    ) {
        parent::__construct($message);
    }
}
