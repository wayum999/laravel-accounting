<?php

declare(strict_types=1);

namespace App\Accounting\Services\FinancialReports;

use App\Accounting\Enums\AccountCategory;
use App\Accounting\Models\Account;
use App\Accounting\Models\AccountType;
use Carbon\Carbon;

class IncomeStatement
{
    /**
     * Generate an income statement (profit & loss) for a date range.
     *
     * Sums income and expense activity within the period, then derives net income.
     * Only journal entries with a post_date falling within [$from, $to] are included.
     *
     * @return array{from: string, to: string, currency: string, income: array, expenses: array, total_income: int, total_expenses: int, net_income: int}
     */
    public static function generate(Carbon $from, Carbon $to, string $currency = 'USD'): array
    {
        $incomeTypeIds  = AccountType::where('type', AccountCategory::INCOME->value)->pluck('id');
        $expenseTypeIds = AccountType::where('type', AccountCategory::EXPENSE->value)->pluck('id');

        $incomeAccounts = Account::whereIn('account_type_id', $incomeTypeIds)
            ->where('currency', $currency)
            ->where('is_active', true)
            ->with('accountType')
            ->get();

        $expenseAccounts = Account::whereIn('account_type_id', $expenseTypeIds)
            ->where('currency', $currency)
            ->where('is_active', true)
            ->with('accountType')
            ->get();

        $incomeRows = [];
        $totalIncome = 0;

        foreach ($incomeAccounts as $account) {
            $amount = static::getBalanceForPeriod($account, $from, $to);
            if ($amount === 0) {
                continue;
            }
            $totalIncome += $amount;
            $incomeRows[] = [
                'account_id'     => $account->id,
                'account_number' => $account->number,
                'account_name'   => $account->name,
                'account_type'   => $account->accountType?->name,
                'amount'         => $amount,
            ];
        }

        $expenseRows = [];
        $totalExpenses = 0;

        foreach ($expenseAccounts as $account) {
            $amount = static::getBalanceForPeriod($account, $from, $to);
            if ($amount === 0) {
                continue;
            }
            $totalExpenses += $amount;
            $expenseRows[] = [
                'account_id'     => $account->id,
                'account_number' => $account->number,
                'account_name'   => $account->name,
                'account_type'   => $account->accountType?->name,
                'amount'         => $amount,
            ];
        }

        return [
            'from'            => $from->toDateString(),
            'to'              => $to->toDateString(),
            'currency'        => $currency,
            'income'          => $incomeRows,
            'expenses'        => $expenseRows,
            'total_income'    => $totalIncome,
            'total_expenses'  => $totalExpenses,
            'net_income'      => $totalIncome - $totalExpenses,
        ];
    }

    /**
     * Calculate the activity balance for an account within a date range.
     *
     * Income accounts (credit-normal):  balance = credits - debits in period
     * Expense accounts (debit-normal):  balance = debits - credits in period
     */
    private static function getBalanceForPeriod(Account $account, Carbon $from, Carbon $to): int
    {
        $debits = (int) ($account->journalEntries()
            ->whereBetween('post_date', [$from, $to])
            ->sum('debit') ?: 0);

        $credits = (int) ($account->journalEntries()
            ->whereBetween('post_date', [$from, $to])
            ->sum('credit') ?: 0);

        $isDebitNormal = $account->accountType?->type?->isDebitNormal() ?? true;

        if ($isDebitNormal) {
            // Expense accounts: activity = debits - credits
            return $debits - $credits;
        }

        // Income accounts: activity = credits - debits
        return $credits - $debits;
    }
}
