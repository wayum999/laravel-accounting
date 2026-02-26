<?php

declare(strict_types=1);

namespace App\Accounting\Exceptions;

class FiscalPeriodOverlapException extends BaseException
{
    public static function forDates(string $start, string $end): self
    {
        return new self("Fiscal period {$start} to {$end} overlaps with an existing period.");
    }
}
