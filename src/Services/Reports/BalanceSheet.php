<?php

declare(strict_types=1);

namespace App\Accounting\Services\Reports;

use App\Accounting\Enums\AccountSubType;
use App\Accounting\Enums\AccountType;
use App\Accounting\Models\Account;
use Carbon\Carbon;

class BalanceSheet
{
    /**
     * Generate a balance sheet as of a specific date.
     *
     * Validates the accounting equation: Assets = Liabilities + Equity
     *
     * @return array{assets: array, liabilities: array, equity: array, grouped_assets: array, grouped_liabilities: array, grouped_equity: array, total_assets: int, total_liabilities: int, total_equity: int, is_balanced: bool, as_of: string, currency: string}
     */
    public static function generate(?Carbon $asOf = null, string $currency = 'USD'): array
    {
        $asOf = $asOf ?? Carbon::now();

        $assets = self::getAccountBalances(AccountType::ASSET, $asOf, $currency);
        $liabilities = self::getAccountBalances(AccountType::LIABILITY, $asOf, $currency);
        $equityAccounts = self::getAccountBalances(AccountType::EQUITY, $asOf, $currency);

        // Net income (income - expenses) is part of equity on the balance sheet
        $incomeStatement = IncomeStatement::generate(
            Carbon::create($asOf->year, 1, 1),
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
     */
    private static function getAccountBalances(AccountType $type, Carbon $asOf, string $currency): array
    {
        $accounts = Account::where('type', $type)
            ->where('currency', $currency)
            ->where('is_active', true)
            ->orderBy('code')
            ->get();

        $rows = [];

        foreach ($accounts as $account) {
            $debits = (int) $account->ledgerEntries()
                ->where('is_posted', true)
                ->where('post_date', '<=', $asOf->copy()->endOfDay())
                ->sum('debit');

            $credits = (int) $account->ledgerEntries()
                ->where('is_posted', true)
                ->where('post_date', '<=', $asOf->copy()->endOfDay())
                ->sum('credit');

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
