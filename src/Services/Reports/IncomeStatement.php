<?php

declare(strict_types=1);

namespace App\Accounting\Services\Reports;

use App\Accounting\Enums\AccountSubType;
use App\Accounting\Enums\AccountType;
use App\Accounting\Models\Account;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class IncomeStatement
{
    /**
     * Generate an income statement (profit & loss) for a date range.
     *
     * Structure follows the QuickBooks multi-step format:
     *   Revenue (operating) + contra-revenue (discounts, returns)
     *   − Cost of Goods Sold
     *   = Gross Profit
     *   − Operating Expenses
     *   = Operating Income
     *   + Other Income (non-operating: interest, gains on sale, etc.)
     *   − Other Expenses (non-operating: losses on sale, etc.)
     *   = Net Income
     *
     * Uses a single GROUP BY query per account type to fetch all
     * debit/credit sums in four round-trips, eliminating the N+1 pattern.
     *
     * Contra-revenue accounts (SALES_DISCOUNTS, SALES_RETURNS_ALLOWANCES) are
     * debit-normal; their balance() returns a negative value which naturally
     * reduces total_revenue without special-casing.
     *
     * @return array{revenue: array, cost_of_goods_sold: array, gross_profit: int, operating_expenses: array, operating_income: int, other_income: array, other_expenses: array, net_income: int, total_revenue: int, total_cogs: int, total_operating_expenses: int, total_other_income: int, total_other_expenses: int, period_start: string, period_end: string, currency: string}
     */
    public static function generate(Carbon $from, Carbon $to, string $currency = 'USD'): array
    {
        $startOfDay = $from->copy()->startOfDay();
        $endOfDay = $to->copy()->endOfDay();

        // Fetch all four account-type groups
        $revenueAccounts = Account::where('type', AccountType::REVENUE)
            ->where('currency', $currency)
            ->where('is_active', true)
            ->orderBy('code')
            ->get();

        $expenseAccounts = Account::where('type', AccountType::EXPENSE)
            ->where('currency', $currency)
            ->where('is_active', true)
            ->orderBy('code')
            ->get();

        $otherIncomeAccounts = Account::where('type', AccountType::OTHER_INCOME)
            ->where('currency', $currency)
            ->where('is_active', true)
            ->orderBy('code')
            ->get();

        $otherExpenseAccounts = Account::where('type', AccountType::OTHER_EXPENSE)
            ->where('currency', $currency)
            ->where('is_active', true)
            ->orderBy('code')
            ->get();

        // Single aggregate query per account-type group
        $revenueBalanceMap = self::fetchBalanceMap($revenueAccounts->pluck('id')->all(), $startOfDay, $endOfDay);
        $expenseBalanceMap = self::fetchBalanceMap($expenseAccounts->pluck('id')->all(), $startOfDay, $endOfDay);
        $otherIncomeBalanceMap = self::fetchBalanceMap($otherIncomeAccounts->pluck('id')->all(), $startOfDay, $endOfDay);
        $otherExpenseBalanceMap = self::fetchBalanceMap($otherExpenseAccounts->pluck('id')->all(), $startOfDay, $endOfDay);

        // --- Revenue (operating) ---
        // Contra-revenue accounts (SALES_DISCOUNTS, SALES_RETURNS_ALLOWANCES) are debit-normal.
        // balance = credits - debits yields a negative value for these, naturally reducing revenue.
        $revenueRows = [];
        $totalRevenue = 0;

        foreach ($revenueAccounts as $account) {
            $row = $revenueBalanceMap->get($account->id);
            $credits = $row ? (int) $row->total_credit : 0;
            $debits = $row ? (int) $row->total_debit : 0;
            $balance = $credits - $debits;

            if ($balance === 0) {
                continue;
            }

            $revenueRows[] = [
                'account_id' => $account->id,
                'code' => $account->code,
                'name' => $account->name,
                'sub_type' => $account->sub_type,
                'amount' => $balance,
            ];
            $totalRevenue += $balance;
        }

        // --- Expenses (operating) ---
        $cogsRows = [];
        $operatingRows = [];
        $uncategorisedRows = [];
        $totalCogs = 0;
        $totalOperating = 0;

        foreach ($expenseAccounts as $account) {
            $row = $expenseBalanceMap->get($account->id);
            $debits = $row ? (int) $row->total_debit : 0;
            $credits = $row ? (int) $row->total_credit : 0;
            $balance = $debits - $credits;

            if ($balance === 0) {
                continue;
            }

            $entry = [
                'account_id' => $account->id,
                'code' => $account->code,
                'name' => $account->name,
                'sub_type' => $account->sub_type,
                'amount' => $balance,
            ];

            if ($account->sub_type === AccountSubType::COST_OF_GOODS_SOLD) {
                $cogsRows[] = $entry;
                $totalCogs += $balance;
            } elseif ($account->sub_type === null) {
                // Null sub_type: explicit uncategorised bucket prevents silent misclassification
                $uncategorisedRows[] = $entry;
                $totalOperating += $balance;
            } else {
                $operatingRows[] = $entry;
                $totalOperating += $balance;
            }
        }

        // --- Other Income (non-operating) ---
        $otherIncomeRows = [];
        $totalOtherIncome = 0;

        foreach ($otherIncomeAccounts as $account) {
            $row = $otherIncomeBalanceMap->get($account->id);
            $credits = $row ? (int) $row->total_credit : 0;
            $debits = $row ? (int) $row->total_debit : 0;
            $balance = $credits - $debits;

            if ($balance === 0) {
                continue;
            }

            $otherIncomeRows[] = [
                'account_id' => $account->id,
                'code' => $account->code,
                'name' => $account->name,
                'sub_type' => $account->sub_type,
                'amount' => $balance,
            ];
            $totalOtherIncome += $balance;
        }

        // --- Other Expenses (non-operating) ---
        $otherExpenseRows = [];
        $totalOtherExpenses = 0;

        foreach ($otherExpenseAccounts as $account) {
            $row = $otherExpenseBalanceMap->get($account->id);
            $debits = $row ? (int) $row->total_debit : 0;
            $credits = $row ? (int) $row->total_credit : 0;
            $balance = $debits - $credits;

            if ($balance === 0) {
                continue;
            }

            $otherExpenseRows[] = [
                'account_id' => $account->id,
                'code' => $account->code,
                'name' => $account->name,
                'sub_type' => $account->sub_type,
                'amount' => $balance,
            ];
            $totalOtherExpenses += $balance;
        }

        $grossProfit = $totalRevenue - $totalCogs;
        $operatingIncome = $grossProfit - $totalOperating;
        $netIncome = $operatingIncome + $totalOtherIncome - $totalOtherExpenses;

        return [
            'revenue' => $revenueRows,
            'cost_of_goods_sold' => $cogsRows,
            'gross_profit' => $grossProfit,
            'operating_expenses' => $operatingRows,
            'uncategorised_expenses' => $uncategorisedRows,
            'operating_income' => $operatingIncome,
            'other_income' => $otherIncomeRows,
            'other_expenses' => $otherExpenseRows,
            'net_income' => $netIncome,

            'total_revenue' => $totalRevenue,
            'total_cogs' => $totalCogs,
            'total_operating_expenses' => $totalOperating,
            'total_other_income' => $totalOtherIncome,
            'total_other_expenses' => $totalOtherExpenses,

            'period_start' => $from->toDateString(),
            'period_end' => $to->toDateString(),
            'currency' => $currency,
        ];
    }

    /**
     * Fetch a map of account_id → {total_debit, total_credit} for the given account IDs
     * and date range using a single GROUP BY query.
     */
    private static function fetchBalanceMap(array $accountIds, Carbon $startOfDay, Carbon $endOfDay): \Illuminate\Support\Collection
    {
        if (empty($accountIds)) {
            return collect();
        }

        return DB::table('accounting_ledger_entries')
            ->whereIn('account_id', $accountIds)
            ->where('is_posted', true)
            ->whereBetween('post_date', [$startOfDay, $endOfDay])
            ->groupBy('account_id')
            ->selectRaw('account_id, SUM(debit) as total_debit, SUM(credit) as total_credit')
            ->get()
            ->keyBy('account_id');
    }
}
