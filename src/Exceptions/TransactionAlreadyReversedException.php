<?php

declare(strict_types=1);

namespace App\Accounting\Exceptions;

class TransactionAlreadyReversedException extends BaseException
{
    public static function forEntry(string $entryId): self
    {
        return new self("Journal entry {$entryId} has already been reversed.");
    }

    public static function forTransactionGroup(string $groupUuid): self
    {
        return new self("Transaction group {$groupUuid} has already been reversed.");
    }
}
