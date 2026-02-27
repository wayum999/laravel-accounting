<?php

declare(strict_types=1);

namespace App\Accounting\Exceptions;

use Exception;

class InvalidEntryMethodException extends Exception
{
    public function __construct(string $method)
    {
        parent::__construct("Invalid journal entry method '{$method}'. Must be 'debit' or 'credit'.");
    }
}
