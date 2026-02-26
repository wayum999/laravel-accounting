<?php

declare(strict_types=1);

namespace App\Accounting\Exceptions;

class PeriodClosedException extends BaseException
{
    public static function forDate(string $date, string $periodName): self
    {
        return new self("Cannot post to {$date}: fiscal period '{$periodName}' is closed.");
    }
}
