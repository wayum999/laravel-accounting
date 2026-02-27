<?php

declare(strict_types=1);

namespace App\Accounting\Exceptions;

use Exception;

class UnbalancedTransactionException extends Exception
{
    public function __construct(int $totalDebits, int $totalCredits)
    {
        parent::__construct(
            "Debits ({$totalDebits}) and credits ({$totalCredits}) do not equal. Difference: " . abs($totalDebits - $totalCredits)
        );
    }
}
