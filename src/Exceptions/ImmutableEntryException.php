<?php

declare(strict_types=1);

namespace App\Accounting\Exceptions;

use Exception;

class ImmutableEntryException extends Exception
{
    public function __construct(string $operation = 'modify')
    {
        parent::__construct(
            "Ledger entries are immutable and cannot be {$operation}d. Use a reversing journal entry instead."
        );
    }
}
