<?php

declare(strict_types=1);

namespace App\Accounting\Services\Reports;

use App\Accounting\Enums\AccountSubType;
use App\Accounting\Enums\AccountType;
use App\Accounting\Models\Account;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class BalanceSheet
{
    /**
     * Generate a balance sheet as of a specific date.
     *
     * Validates the accounting equation: Assets = Liabilities + Equity
     *
     * @param  Carbon|null  $periodStart  Start of the income period for net income calculation.
     *                                    Defaults to January 1 of $asOf->year. Override for
     *                                    non-calendar fiscal years.
     *
     * @return array{assets: array, liabilities: array, equity: array, grouped_assets: array, grouped_liabilities: array, grouped_equity: array, total_assets: int, total_liabilities: int, total_equity: int, is_balanced: bool, as_of: string, currency: string}
     */
    public static function generate(?Carbon $asOf = null, string $currency = 'USD', ?Carbon $periodStart = null): array
    {
        $asOf = $asOf ?? Carbon::now();
        $periodStart = $periodStart ?? Carbon::create($asOf->year, 1, 1);

        $assets = self::getAccountBalances(AccountType::ASSET, $asOf, $currency);
        $liabilities = self::getAccountBalances(AccountType::LIABILITY, $asOf, $currency);
        $equityAccounts = self::getAccountBalances(AccountType::EQUITY, $asOf, $currency);

        // Net income (income - expenses) is part of equity on the balance sheet
        $incomeStatement = IncomeStatement::generate(
            $periodStart,
            $asOf,
            $currency,
        );

        $netIncome = $incomeStatement['net_income'];

        $totalAssets = array_sum(array_column($assets, 'amount'));
        $totalLiabilities = array_sum(array_column($liabilities, 'amount'));
        $totalEquity = array_sum(array_column($equityAccounts, 'amount'));

        // Add net income to equity section
        if ($netIncome !== 0) {
            $equityAccounts[] = [
                'account_id' => null,
                'code' => null,
                'name' => 'Net Income (Current Year)',
                'sub_type' => null,
                'amount' => $netIncome,
            ];
            $totalEquity += $netIncome;
        }

        return [
            // Flat arrays (backward compatible)
            'assets' => $assets,
            'liabilities' => $liabilities,
            'equity' => $equityAccounts,
            // Grouped by report section
            'grouped_assets' => self::groupByReportGroup($assets),
            'grouped_liabilities' => self::groupByReportGroup($liabilities),
            'grouped_equity' => self::groupByReportGroup($equityAccounts),
            // Totals
            'total_assets' => $totalAssets,
            'total_liabilities' => $totalLiabilities,
            'total_equity' => $totalEquity,
            'is_balanced' => $totalAssets === ($totalLiabilities + $totalEquity),
            'as_of' => $asOf->toDateString(),
            'currency' => $currency,
        ];
    }

    /**
     * Get all account balances for a given type as of a date.
     *
     * Uses a single GROUP BY query to fetch all debit/credit sums in one round-trip,
     * eliminating the N+1 pattern of two queries per account.
     */
    private static function getAccountBalances(AccountType $type, Carbon $asOf, string $currency): array
    {
        $accounts = Account::where('type', $type)
            ->where('currency', $currency)
            ->where('is_active', true)
            ->orderBy('code')
            ->get();

        if ($accounts->isEmpty()) {
            return [];
        }

        $endOfDay = $asOf->copy()->endOfDay();
        $accountIds = $accounts->pluck('id')->all();

        // Single aggregate query for all accounts of this type
        $balanceMap = DB::table('accounting_ledger_entries')
            ->whereIn('account_id', $accountIds)
            ->where('is_posted', true)
            ->where('post_date', '<=', $endOfDay)
            ->groupBy('account_id')
            ->selectRaw('account_id, SUM(debit) as total_debit, SUM(credit) as total_credit')
            ->get()
            ->keyBy('account_id');

        $rows = [];

        foreach ($accounts as $account) {
            $row = $balanceMap->get($account->id);
            $debits = $row ? (int) $row->total_debit : 0;
            $credits = $row ? (int) $row->total_credit : 0;

            $balance = $account->isDebitNormal()
                ? $debits - $credits
                : $credits - $debits;

            if ($balance === 0) {
                continue;
            }

            $rows[] = [
                'account_id' => $account->id,
                'code' => $account->code,
                'name' => $account->name,
                'sub_type' => $account->sub_type,
                'amount' => $balance,
            ];
        }

        return $rows;
    }

    /**
     * Group account rows by their sub-type's report group label.
     */
    private static function groupByReportGroup(array $rows): array
    {
        $groups = [];

        foreach ($rows as $row) {
            $group = $row['sub_type'] instanceof AccountSubType
                ? $row['sub_type']->reportGroup()
                : 'Other';
            $groups[$group][] = $row;
        }

        return $groups;
    }
}
