<?php

declare(strict_types=1);

namespace App\Accounting\Services\FinancialReports;

use App\Accounting\Models\Account;
use Carbon\Carbon;

class TrialBalance
{
    /**
     * Generate a trial balance report.
     *
     * Lists every active account with its balance split into a debit or credit column,
     * confirming that total debits equal total credits (the fundamental double-entry check).
     *
     * @return array{as_of: string, currency: string, accounts: array, total_debits: int, total_credits: int, is_balanced: bool}
     */
    public static function generate(
        ?Carbon $asOf = null,
        string $currency = 'USD',
        bool $includeZeroBalances = false
    ): array {
        $accounts = Account::with('accountType')
            ->where('currency', $currency)
            ->where('is_active', true)
            ->get();

        $rows = [];
        $totalDebits = 0;
        $totalCredits = 0;

        foreach ($accounts as $account) {
            $balance = $asOf
                ? $account->getBalanceOn($asOf)
                : $account->getBalance();

            $balanceAmount = (int) $balance->getAmount();

            if ($balanceAmount === 0 && !$includeZeroBalances) {
                continue;
            }

            $isDebitNormal = $account->accountType?->type?->isDebitNormal() ?? true;

            $debit = 0;
            $credit = 0;

            if ($isDebitNormal) {
                if ($balanceAmount >= 0) {
                    $debit = $balanceAmount;
                } else {
                    $credit = abs($balanceAmount);
                }
            } else {
                if ($balanceAmount >= 0) {
                    $credit = $balanceAmount;
                } else {
                    $debit = abs($balanceAmount);
                }
            }

            $totalDebits += $debit;
            $totalCredits += $credit;

            $rows[] = [
                'account_id'   => $account->id,
                'account_number' => $account->number,
                'account_name' => $account->name,
                'account_type' => $account->accountType?->name,
                'category'     => $account->accountType?->type?->value,
                'debit'        => $debit,
                'credit'       => $credit,
            ];
        }

        return [
            'as_of'         => ($asOf ?? Carbon::now())->toDateString(),
            'currency'      => $currency,
            'accounts'      => $rows,
            'total_debits'  => $totalDebits,
            'total_credits' => $totalCredits,
            'is_balanced'   => $totalDebits === $totalCredits,
        ];
    }
}
