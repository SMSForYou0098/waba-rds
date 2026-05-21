<?php

namespace App\Exceptions\Billing;

use Exception;

class InsufficientCreditsException extends Exception
{
    public function __construct(
        public readonly float $currentBalance,
        public readonly float $required,
        string $message = 'Insufficient credits to send this message.',
    ) {
        parent::__construct($message);
    }
}
