<?php

declare(strict_types=1);

namespace App\Accounting\Enums;

/**
 * Status values for fiscal periods.
 */
enum FiscalPeriodStatus: string
{
    case OPEN = 'open';
    case CLOSED = 'closed';

    public function isOpen(): bool
    {
        return $this === self::OPEN;
    }

    public function isClosed(): bool
    {
        return $this === self::CLOSED;
    }
}
