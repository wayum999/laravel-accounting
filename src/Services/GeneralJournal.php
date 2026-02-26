<?php

declare(strict_types=1);

namespace App\Accounting\Services;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use App\Accounting\Models\JournalEntry;

/**
 * General Journal service.
 *
 * Provides a chronological view of all journal entries -- the book of original entry.
 * Every transaction in the system flows through the General Journal.
 */
class GeneralJournal
{
    /**
     * Get all posted journal entries, optionally filtered by date range and currency.
     *
     * @return Builder
     */
    public static function entries(
        ?Carbon $from = null,
        ?Carbon $to = null,
        ?string $currency = null,
        bool $includeUnposted = false
    ): Builder {
        $query = JournalEntry::query()
            ->with('account.accountType')
            ->orderBy('post_date')
            ->orderBy('transaction_group')
            ->orderBy('created_at');

        if (! $includeUnposted) {
            $query->where('is_posted', true);
        }

        if ($from) {
            $query->where('post_date', '>=', $from);
        }

        if ($to) {
            $query->where('post_date', '<=', $to);
        }

        if ($currency) {
            $query->where('currency', $currency);
        }

        return $query;
    }

    /**
     * Get entries grouped by transaction_group UUID.
     * Each group represents a complete double-entry transaction.
     */
    public static function transactionGroups(
        ?Carbon $from = null,
        ?Carbon $to = null,
        ?string $currency = null
    ): Collection {
        return static::entries($from, $to, $currency)
            ->get()
            ->groupBy('transaction_group');
    }

    /**
     * Get entries for a specific transaction group.
     */
    public static function getTransactionGroup(string $transactionGroupUuid): Collection
    {
        return JournalEntry::query()
            ->with('account.accountType')
            ->where('transaction_group', $transactionGroupUuid)
            ->orderBy('post_date')
            ->orderBy('created_at')
            ->get();
    }
}
