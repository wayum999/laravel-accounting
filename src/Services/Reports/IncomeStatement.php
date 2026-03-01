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
     * Uses a single GROUP BY query per account type (income / expense) to fetch all
     * debit/credit sums in two round-trips, eliminating the N+1 pattern.
     *
     * @return array{income: array, expenses: array, total_income: int, total_expenses: int, net_income: int, revenue: array, cost_of_goods_sold: array, gross_profit: int, operating_expenses: array, operating_income: int, other_income: array, other_expenses: array, total_revenue: int, total_cogs: int, total_operating_expenses: int, total_other_income: int, total_other_expenses: int, period_start: string, period_end: string, currency: string}
     */
    public static function generate(Carbon $from, Carbon $to, string $currency = 'USD'): array
    {
        $startOfDay = $from->copy()->startOfDay();
        $endOfDay = $to->copy()->endOfDay();

        $incomeAccounts = Account::where('type', AccountType::INCOME)
            ->where('currency', $currency)
            ->where('is_active', true)
            ->orderBy('code')
            ->get();

        $expenseAccounts = Account::where('type', AccountType::EXPENSE)
            ->where('currency', $currency)
            ->where('is_active', true)
            ->orderBy('code')
            ->get();

        // Single aggregate query for all income accounts
        $incomeBalanceMap = self::fetchBalanceMap(
            $incomeAccounts->pluck('id')->all(),
            $startOfDay,
            $endOfDay,
        );

        // Single aggregate query for all expense accounts
        $expenseBalanceMap = self::fetchBalanceMap(
            $expenseAccounts->pluck('id')->all(),
            $startOfDay,
            $endOfDay,
        );

        // Categorize income
        $revenueRows = [];
        $otherIncomeRows = [];
        $totalRevenue = 0;
        $totalOtherIncome = 0;

        foreach ($incomeAccounts as $account) {
            $row = $incomeBalanceMap->get($account->id);
            $credits = $row ? (int) $row->total_credit : 0;
            $debits = $row ? (int) $row->total_debit : 0;

            // Income is credit-normal: balance = credits - debits
            $balance = $credits - $debits;

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

            if ($account->sub_type === AccountSubType::OTHER_INCOME) {
                $otherIncomeRows[] = $entry;
                $totalOtherIncome += $balance;
            } else {
                // Accounts with null sub_type fall into revenue (main income bucket)
                $revenueRows[] = $entry;
                $totalRevenue += $balance;
            }
        }

        // Categorize expenses
        $cogsRows = [];
        $operatingRows = [];
        $otherExpenseRows = [];
        $uncategorisedRows = [];
        $totalCogs = 0;
        $totalOperating = 0;
        $totalOtherExpenses = 0;

        foreach ($expenseAccounts as $account) {
            $row = $expenseBalanceMap->get($account->id);
            $debits = $row ? (int) $row->total_debit : 0;
            $credits = $row ? (int) $row->total_credit : 0;

            // Expense is debit-normal: balance = debits - credits
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
            } elseif ($account->sub_type === AccountSubType::OTHER_EXPENSE) {
                $otherExpenseRows[] = $entry;
                $totalOtherExpenses += $balance;
            } elseif ($account->sub_type === null) {
                // Null sub_type: route to an explicit uncategorised bucket rather than
                // silently promoting to operating expenses (prevents silent misclassification)
                $uncategorisedRows[] = $entry;
                $totalOperating += $balance;
            } else {
                $operatingRows[] = $entry;
                $totalOperating += $balance;
            }
        }

        $totalIncome = $totalRevenue + $totalOtherIncome;
        $totalExpenses = $totalCogs + $totalOperating + $totalOtherExpenses;
        $grossProfit = $totalRevenue - $totalCogs;
        $operatingIncome = $grossProfit - $totalOperating;

        return [
            // Backward-compatible flat arrays
            'income' => array_merge($revenueRows, $otherIncomeRows),
            'expenses' => array_merge($cogsRows, $operatingRows, $uncategorisedRows, $otherExpenseRows),
            'total_income' => $totalIncome,
            'total_expenses' => $totalExpenses,
            'net_income' => $totalIncome - $totalExpenses,

            // Detailed structure
            'revenue' => $revenueRows,
            'cost_of_goods_sold' => $cogsRows,
            'gross_profit' => $grossProfit,
            'operating_expenses' => $operatingRows,
            'uncategorised_expenses' => $uncategorisedRows,
            'operating_income' => $operatingIncome,
            'other_income' => $otherIncomeRows,
            'other_expenses' => $otherExpenseRows,
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
