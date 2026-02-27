<?php

declare(strict_types=1);

namespace App\Accounting\Exceptions;

use Exception;

class AccountAlreadyExistsException extends Exception
{
    public function __construct(string $message = 'This model already has an accounting account.')
    {
        parent::__construct($message);
    }
}
