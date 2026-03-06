<?php

declare(strict_types=1);

namespace App\Accounting\Services\Reports;

use App\Accounting\Enums\AccountSubType;
use App\Accounting\Enums\AccountType;
use App\Accounting\Models\Account;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

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
     * Uses database-side conditional aggregation to compute bucket totals, avoiding
     * loading unbounded ledger entry history into PHP memory.
     *
     * Future-dated entries (post_date > $asOf) are excluded from all buckets rather
     * than being incorrectly bucketed by an absolute day difference.
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

        $asOfDate = $asOf->toDateString();

        foreach ($accounts as $account) {
            $isDebitNormal = $account->isDebitNormal();

            // Amount expression: positive net balance in the account's normal direction.
            // Using CASE WHEN instead of GREATEST() for cross-database compatibility
            // (SQLite does not support GREATEST(); MySQL/PostgreSQL do).
            $netExpr = $isDebitNormal
                ? 'COALESCE(debit, 0) - COALESCE(credit, 0)'
                : 'COALESCE(credit, 0) - COALESCE(debit, 0)';
            $amountExpr = "CASE WHEN ({$netExpr}) > 0 THEN ({$netExpr}) ELSE 0 END";

            // Signed days aged: positive = past, negative = future-dated (excluded)
            // Branch on database driver for cross-DB compatibility
            $driver = DB::getDriverName();
            $daysDiffExpr = match ($driver) {
                'mysql' => "(DATEDIFF('{$asOfDate}', DATE(post_date)))",
                'pgsql' => "('{$asOfDate}'::date - post_date::date)",
                default => "(JULIANDAY('{$asOfDate}') - JULIANDAY(DATE(post_date)))",
            };

            $selectParts = ["SUM({$amountExpr}) as account_total"];

            foreach ($buckets as $index => $bucket) {
                $min = (int) $bucket['min'];
                $max = $bucket['max'];

                if ($max === null) {
                    $bucketCondition = "{$daysDiffExpr} >= {$min}";
                } else {
                    $bucketMax = (int) $max;
                    $bucketCondition = "{$daysDiffExpr} BETWEEN {$min} AND {$bucketMax}";
                }

                // Exclude future-dated entries (negative day diff)
                $selectParts[] = "SUM(CASE WHEN {$daysDiffExpr} >= 0 AND {$bucketCondition} THEN {$amountExpr} ELSE 0 END) as bucket_{$index}";
            }

            $row = DB::table('accounting_ledger_entries')
                ->where('account_id', $account->id)
                ->where('is_posted', true)
                ->where('post_date', '<=', $asOf->copy()->endOfDay())
                ->selectRaw(implode(', ', $selectParts))
                ->first();

            $accountTotal = $row ? (int) $row->account_total : 0;

            if ($accountTotal === 0) {
                continue;
            }

            $totalOutstanding += $accountTotal;

            $bucketDetail = [];
            foreach ($buckets as $index => $bucket) {
                $bucketAmount = $row ? (int) ($row->{"bucket_{$index}"} ?? 0) : 0;
                $summaryTotals[$index] += $bucketAmount;
                $bucketDetail[] = [
                    'label' => $bucket['label'],
                    'amount' => $bucketAmount,
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
