<?php

namespace App\Exceptions\Billing;

use Exception;

class MessageAlreadyBilledException extends Exception
{
    public function __construct(
        public readonly string $wamid,
        public readonly int $outReportId,
        string $message = 'This message was already billed.',
    ) {
        parent::__construct($message);
    }
}
