<?php

declare(strict_types=1);

namespace App\Accounting\Exceptions;

use Exception;

class InvalidAmountException extends Exception
{
    public function __construct(string $message = 'Transaction amount must be greater than zero.')
    {
        parent::__construct($message);
    }
}
