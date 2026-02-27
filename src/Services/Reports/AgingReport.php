<?php

declare(strict_types=1);

namespace App\Accounting\Services\Reports;

use App\Accounting\Enums\AccountSubType;
use App\Accounting\Enums\AccountType;
use App\Accounting\Models\Account;
use Carbon\Carbon;

class AgingReport
{
    /**
     * Default aging buckets (in days).
     */
    private const DEFAULT_BUCKETS = [
        ['label' => 'Current (0-30)', 'min' => 0, 'max' => 30],
        ['label' => '31-60', 'min' => 31, 'max' => 60],
        ['label' => '61-90', 'min' => 61, 'max' => 90],
        ['label' => '90+', 'min' => 91, 'max' => null],
    ];

    /**
     * Generate an aging report for receivables or payables.
     *
     * @param  AccountType  $type  Typically ASSET (for AR aging) or LIABILITY (for AP aging)
     * @param  Carbon|null  $asOf  Date to calculate aging from
     * @param  array|null  $customBuckets  Custom bucket definitions
     * @return array{as_of: string, account_type: string, currency: string, details: array, summary: array, total_outstanding: int}
     */
    public static function generate(
        AccountType $type,
        ?Carbon $asOf = null,
        ?array $customBuckets = null,
        string $currency = 'USD',
    ): array {
        $asOf = $asOf ?? Carbon::now();
        $buckets = $customBuckets ?? self::DEFAULT_BUCKETS;

        // Get relevant accounts (e.g., AR accounts for asset type)
        $subTypes = match ($type) {
            AccountType::ASSET => [AccountSubType::ACCOUNTS_RECEIVABLE],
            AccountType::LIABILITY => [AccountSubType::ACCOUNTS_PAYABLE],
            default => null,
        };

        $query = Account::where('type', $type)
            ->where('currency', $currency)
            ->where('is_active', true)
            ->orderBy('code');

        if ($subTypes) {
            $query->whereIn('sub_type', $subTypes);
        }

        $accounts = $query->get();

        $details = [];
        $summaryTotals = array_fill(0, count($buckets), 0);
        $totalOutstanding = 0;

        foreach ($accounts as $account) {
            $accountBuckets = array_fill(0, count($buckets), 0);
            $accountTotal = 0;

            // Get all posted unmatched/outstanding entries for this account
            $entries = $account->ledgerEntries()
                ->where('is_posted', true)
                ->where('post_date', '<=', $asOf->copy()->endOfDay())
                ->orderBy('post_date')
                ->get();

            foreach ($entries as $entry) {
                // Calculate the amount for this entry
                $amount = $account->isDebitNormal()
                    ? $entry->debit - $entry->credit
                    : $entry->credit - $entry->debit;

                if ($amount <= 0) {
                    continue;
                }

                // Calculate days aged
                $daysAged = $entry->post_date->diffInDays($asOf);

                // Place into appropriate bucket
                foreach ($buckets as $index => $bucket) {
                    $min = $bucket['min'];
                    $max = $bucket['max'];

                    if ($daysAged >= $min && ($max === null || $daysAged <= $max)) {
                        $accountBuckets[$index] += $amount;
                        $summaryTotals[$index] += $amount;
                        break;
                    }
                }

                $accountTotal += $amount;
            }

            if ($accountTotal === 0) {
                continue;
            }

            $totalOutstanding += $accountTotal;

            $bucketDetail = [];
            foreach ($buckets as $index => $bucket) {
                $bucketDetail[] = [
                    'label' => $bucket['label'],
                    'amount' => $accountBuckets[$index],
                ];
            }

            $details[] = [
                'account_id' => $account->id,
                'code' => $account->code,
                'name' => $account->name,
                'total' => $accountTotal,
                'buckets' => $bucketDetail,
            ];
        }

        $summary = [];
        foreach ($buckets as $index => $bucket) {
            $summary[] = [
                'label' => $bucket['label'],
                'amount' => $summaryTotals[$index],
            ];
        }

        return [
            'as_of' => $asOf->toDateString(),
            'account_type' => $type->value,
            'currency' => $currency,
            'details' => $details,
            'summary' => $summary,
            'total_outstanding' => $totalOutstanding,
        ];
    }
}
