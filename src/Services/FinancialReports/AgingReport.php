<?php

declare(strict_types=1);

namespace App\Accounting\Services\FinancialReports;

use App\Accounting\Enums\AccountCategory;
use App\Accounting\Models\Account;
use App\Accounting\Models\AccountType;
use App\Accounting\Models\JournalEntry;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class AgingReport
{
    /**
     * Default aging buckets (in days).
     */
    private const DEFAULT_BUCKETS = [
        ['min' => 0, 'max' => 30, 'label' => 'Current'],
        ['min' => 31, 'max' => 60, 'label' => '31-60'],
        ['min' => 61, 'max' => 90, 'label' => '61-90'],
        ['min' => 91, 'max' => 120, 'label' => '91-120'],
        ['min' => 121, 'max' => null, 'label' => '120+'],
    ];

    /**
     * Generate an aging report for accounts under a specific account type.
     *
     * Groups outstanding balances into age buckets based on the post_date of each
     * journal entry relative to the as-of date. Entries that net to zero are excluded.
     *
     * @param AccountType $accountType The account type to age (e.g., Accounts Receivable)
     * @param Carbon|null $asOf The date to calculate aging from (defaults to now)
     * @param array|null $buckets Custom bucket definitions [[min, max, label], ...]
     * @param string $currency Currency to filter by
     * @return array{as_of: string, account_type: string, category: string, currency: string, details: array, summary: array, total_outstanding: int}
     */
    public static function generate(
        AccountType $accountType,
        ?Carbon $asOf = null,
        ?array $buckets = null,
        string $currency = 'USD'
    ): array {
        $asOf    = $asOf ?? Carbon::now();
        $buckets = $buckets ?? self::DEFAULT_BUCKETS;

        $accounts = $accountType->accounts()
            ->where('currency', $currency)
            ->where('is_active', true)
            ->get();

        $details      = [];
        $bucketTotals = array_fill_keys(array_column($buckets, 'label'), 0);
        $grandTotal   = 0;

        foreach ($accounts as $account) {
            $entries = $account->journalEntries()
                ->where('is_posted', true)
                ->where('post_date', '<=', $asOf)
                ->get();

            if ($entries->isEmpty()) {
                continue;
            }

            // Group entries by referenced entity when available; otherwise by account.
            $grouped = $entries->groupBy(function ($entry) {
                if ($entry->ref_class && $entry->ref_class_id) {
                    return $entry->ref_class . ':' . $entry->ref_class_id;
                }

                return 'account:' . $entry->account_id;
            });

            $isDebitNormal = $accountType->type->isDebitNormal();

            foreach ($grouped as $groupKey => $groupEntries) {
                $accountBuckets = array_fill_keys(array_column($buckets, 'label'), 0);

                foreach ($groupEntries as $entry) {
                    $daysOld     = (int) $entry->post_date->diffInDays($asOf);
                    $bucketLabel = self::getBucketLabel($daysOld, $buckets);

                    // Calculate net amount: positive means balance is outstanding.
                    $amount = $isDebitNormal
                        ? (($entry->debit ?? 0) - ($entry->credit ?? 0))
                        : (($entry->credit ?? 0) - ($entry->debit ?? 0));

                    $accountBuckets[$bucketLabel] += $amount;
                }

                $total = array_sum($accountBuckets);

                // Skip groups that have fully cleared (net zero).
                if ($total === 0) {
                    continue;
                }

                foreach ($accountBuckets as $label => $amount) {
                    $bucketTotals[$label] += $amount;
                }

                $grandTotal += $total;

                $details[] = [
                    'account_id'     => $account->id,
                    'account_name'   => $account->name,
                    'account_number' => $account->number,
                    'reference'      => $groupKey,
                    'buckets'        => $accountBuckets,
                    'total'          => $total,
                ];
            }
        }

        // Build summary rows with percentage of grand total.
        $summary = [];
        foreach ($buckets as $bucket) {
            $label    = $bucket['label'];
            $amount   = $bucketTotals[$label];
            $summary[] = [
                'label'      => $label,
                'amount'     => $amount,
                'percentage' => $grandTotal > 0 ? round(($amount / $grandTotal) * 100, 2) : 0.0,
            ];
        }

        return [
            'as_of'             => $asOf->toDateString(),
            'account_type'      => $accountType->name,
            'category'          => $accountType->type->value,
            'currency'          => $currency,
            'details'           => $details,
            'summary'           => $summary,
            'total_outstanding' => $grandTotal,
        ];
    }

    /**
     * Convenience method for Accounts Receivable aging.
     *
     * Searches for an AccountType whose category is ASSET and whose name contains
     * "Receivable" or whose code contains "AR".
     *
     * @return array{as_of: string, account_type: string, category: string, currency: string, details: array, summary: array, total_outstanding: int}
     */
    public static function receivables(
        ?Carbon $asOf = null,
        ?array $buckets = null,
        string $currency = 'USD'
    ): array {
        $arType = AccountType::where('type', AccountCategory::ASSET->value)
            ->where(function ($q) {
                $q->where('name', 'like', '%Receivable%')
                  ->orWhere('code', 'like', '%AR%');
            })
            ->first();

        if (! $arType) {
            return [
                'as_of'             => ($asOf ?? Carbon::now())->toDateString(),
                'account_type'      => 'Accounts Receivable',
                'category'          => AccountCategory::ASSET->value,
                'currency'          => $currency,
                'details'           => [],
                'summary'           => [],
                'total_outstanding' => 0,
            ];
        }

        return static::generate($arType, $asOf, $buckets, $currency);
    }

    /**
     * Convenience method for Accounts Payable aging.
     *
     * Searches for an AccountType whose category is LIABILITY and whose name contains
     * "Payable" or whose code contains "AP".
     *
     * @return array{as_of: string, account_type: string, category: string, currency: string, details: array, summary: array, total_outstanding: int}
     */
    public static function payables(
        ?Carbon $asOf = null,
        ?array $buckets = null,
        string $currency = 'USD'
    ): array {
        $apType = AccountType::where('type', AccountCategory::LIABILITY->value)
            ->where(function ($q) {
                $q->where('name', 'like', '%Payable%')
                  ->orWhere('code', 'like', '%AP%');
            })
            ->first();

        if (! $apType) {
            return [
                'as_of'             => ($asOf ?? Carbon::now())->toDateString(),
                'account_type'      => 'Accounts Payable',
                'category'          => AccountCategory::LIABILITY->value,
                'currency'          => $currency,
                'details'           => [],
                'summary'           => [],
                'total_outstanding' => 0,
            ];
        }

        return static::generate($apType, $asOf, $buckets, $currency);
    }

    /**
     * Find the bucket label for a given number of days.
     *
     * Iterates buckets in definition order. The first bucket whose range contains
     * $days wins. An open-ended bucket (max === null) matches any days >= min.
     */
    private static function getBucketLabel(int $days, array $buckets): string
    {
        foreach ($buckets as $bucket) {
            $min = $bucket['min'];
            $max = $bucket['max'];

            if ($max === null && $days >= $min) {
                return $bucket['label'];
            }

            if ($days >= $min && $days <= $max) {
                return $bucket['label'];
            }
        }

        // Fallback: assign to the last defined bucket.
        return end($buckets)['label'];
    }
}
