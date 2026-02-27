<?php

declare(strict_types=1);

namespace App\Accounting\Services\Reports;

use App\Accounting\Models\Account;
use Carbon\Carbon;

class TrialBalance
{
    /**
     * Generate a trial balance report.
     *
     * @return array{accounts: array, total_debits: int, total_credits: int, is_balanced: bool, as_of: string, currency: string}
     */
    public static function generate(?Carbon $asOf = null, string $currency = 'USD', bool $includeZero = false): array
    {
        $asOf = $asOf ?? Carbon::now();

        $query = Account::where('currency', $currency)
            ->where('is_active', true)
            ->orderBy('code');

        $accounts = $query->get();

        $rows = [];
        $totalDebits = 0;
        $totalCredits = 0;

        foreach ($accounts as $account) {
            $debits = (int) $account->ledgerEntries()
                ->where('is_posted', true)
                ->where('post_date', '<=', $asOf->copy()->endOfDay())
                ->sum('debit');

            $credits = (int) $account->ledgerEntries()
                ->where('is_posted', true)
                ->where('post_date', '<=', $asOf->copy()->endOfDay())
                ->sum('credit');

            // For the trial balance, we show each account's balance
            // in the appropriate debit or credit column based on its net position
            $balance = $account->isDebitNormal()
                ? $debits - $credits
                : $credits - $debits;

            if (!$includeZero && $balance === 0) {
                continue;
            }

            $debitBalance = 0;
            $creditBalance = 0;

            if ($account->isDebitNormal()) {
                if ($balance >= 0) {
                    $debitBalance = $balance;
                } else {
                    $creditBalance = abs($balance);
                }
            } else {
                if ($balance >= 0) {
                    $creditBalance = $balance;
                } else {
                    $debitBalance = abs($balance);
                }
            }

            $totalDebits += $debitBalance;
            $totalCredits += $creditBalance;

            $rows[] = [
                'account_id' => $account->id,
                'code' => $account->code,
                'name' => $account->name,
                'type' => $account->type->value,
                'debit' => $debitBalance,
                'credit' => $creditBalance,
            ];
        }

        return [
            'accounts' => $rows,
            'total_debits' => $totalDebits,
            'total_credits' => $totalCredits,
            'is_balanced' => $totalDebits === $totalCredits,
            'as_of' => $asOf->toDateString(),
            'currency' => $currency,
        ];
    }
}
