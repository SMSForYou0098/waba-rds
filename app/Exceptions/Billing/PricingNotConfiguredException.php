<?php

namespace App\Exceptions\Billing;

use Exception;

class PricingNotConfiguredException extends Exception
{
    public function __construct(string $message = 'Pricing is not configured for this account.')
    {
        parent::__construct($message);
    }
}
