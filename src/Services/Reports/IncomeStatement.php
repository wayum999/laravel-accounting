<?php

declare(strict_types=1);

namespace App\Accounting\Services\Reports;

use App\Accounting\Enums\AccountSubType;
use App\Accounting\Enums\AccountType;
use App\Accounting\Models\Account;
use Carbon\Carbon;

class IncomeStatement
{
    /**
     * Generate an income statement (profit & loss) for a date range.
     *
     * @return array{income: array, expenses: array, total_income: int, total_expenses: int, net_income: int, revenue: array, cost_of_goods_sold: array, gross_profit: int, operating_expenses: array, operating_income: int, other_income: array, other_expenses: array, total_revenue: int, total_cogs: int, total_operating_expenses: int, total_other_income: int, total_other_expenses: int, period_start: string, period_end: string, currency: string}
     */
    public static function generate(Carbon $from, Carbon $to, string $currency = 'USD'): array
    {
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

        // Categorize income
        $revenueRows = [];
        $otherIncomeRows = [];
        $totalRevenue = 0;
        $totalOtherIncome = 0;

        foreach ($incomeAccounts as $account) {
            $credits = (int) $account->ledgerEntries()
                ->where('is_posted', true)
                ->whereBetween('post_date', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])
                ->sum('credit');

            $debits = (int) $account->ledgerEntries()
                ->where('is_posted', true)
                ->whereBetween('post_date', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])
                ->sum('debit');

            // Income is credit-normal: balance = credits - debits
            $balance = $credits - $debits;

            if ($balance === 0) {
                continue;
            }

            $row = [
                'account_id' => $account->id,
                'code' => $account->code,
                'name' => $account->name,
                'sub_type' => $account->sub_type,
                'amount' => $balance,
            ];

            if ($account->sub_type === AccountSubType::OTHER_INCOME) {
                $otherIncomeRows[] = $row;
                $totalOtherIncome += $balance;
            } else {
                $revenueRows[] = $row;
                $totalRevenue += $balance;
            }
        }

        // Categorize expenses
        $cogsRows = [];
        $operatingRows = [];
        $otherExpenseRows = [];
        $totalCogs = 0;
        $totalOperating = 0;
        $totalOtherExpenses = 0;

        foreach ($expenseAccounts as $account) {
            $debits = (int) $account->ledgerEntries()
                ->where('is_posted', true)
                ->whereBetween('post_date', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])
                ->sum('debit');

            $credits = (int) $account->ledgerEntries()
                ->where('is_posted', true)
                ->whereBetween('post_date', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])
                ->sum('credit');

            // Expense is debit-normal: balance = debits - credits
            $balance = $debits - $credits;

            if ($balance === 0) {
                continue;
            }

            $row = [
                'account_id' => $account->id,
                'code' => $account->code,
                'name' => $account->name,
                'sub_type' => $account->sub_type,
                'amount' => $balance,
            ];

            if ($account->sub_type === AccountSubType::COST_OF_GOODS_SOLD) {
                $cogsRows[] = $row;
                $totalCogs += $balance;
            } elseif ($account->sub_type === AccountSubType::OTHER_EXPENSE) {
                $otherExpenseRows[] = $row;
                $totalOtherExpenses += $balance;
            } else {
                $operatingRows[] = $row;
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
            'expenses' => array_merge($cogsRows, $operatingRows, $otherExpenseRows),
            'total_income' => $totalIncome,
            'total_expenses' => $totalExpenses,
            'net_income' => $totalIncome - $totalExpenses,

            // Detailed structure
            'revenue' => $revenueRows,
            'cost_of_goods_sold' => $cogsRows,
            'gross_profit' => $grossProfit,
            'operating_expenses' => $operatingRows,
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
}
