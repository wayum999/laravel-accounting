<?php

declare(strict_types=1);

namespace App\Accounting\Enums;

/**
 * Status values for non-posting transactions (quotes, proposals, POs, etc.).
 */
enum NonPostingStatus: string
{
    case DRAFT = 'draft';
    case OPEN = 'open';
    case CLOSED = 'closed';
    case VOIDED = 'voided';
    case CONVERTED = 'converted';

    /**
     * Gets all possible values.
     *
     * @return array<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * Whether this status allows conversion to a posting transaction.
     */
    public function canConvert(): bool
    {
        return in_array($this, [
            self::DRAFT,
            self::OPEN,
        ]);
    }
}
