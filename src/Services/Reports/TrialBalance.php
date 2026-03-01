<?php

declare(strict_types=1);

namespace App\Accounting\Services\Reports;

use App\Accounting\Models\Account;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class TrialBalance
{
    /**
     * Generate a trial balance report.
     *
     * Uses a single GROUP BY query to fetch all debit/credit sums in one round-trip,
     * eliminating the N+1 pattern of two queries per account.
     *
     * @return array{accounts: array, total_debits: int, total_credits: int, is_balanced: bool, as_of: string, currency: string}
     */
    public static function generate(?Carbon $asOf = null, string $currency = 'USD', bool $includeZero = false): array
    {
        $asOf = $asOf ?? Carbon::now();
        $endOfDay = $asOf->copy()->endOfDay();

        $accounts = Account::where('currency', $currency)
            ->where('is_active', true)
            ->orderBy('code')
            ->get();

        if ($accounts->isEmpty()) {
            return [
                'accounts' => [],
                'total_debits' => 0,
                'total_credits' => 0,
                'is_balanced' => true,
                'as_of' => $asOf->toDateString(),
                'currency' => $currency,
            ];
        }

        // Single aggregate query for all active accounts
        $balanceMap = DB::table('accounting_ledger_entries')
            ->whereIn('account_id', $accounts->pluck('id')->all())
            ->where('is_posted', true)
            ->where('post_date', '<=', $endOfDay)
            ->groupBy('account_id')
            ->selectRaw('account_id, SUM(debit) as total_debit, SUM(credit) as total_credit')
            ->get()
            ->keyBy('account_id');

        $rows = [];
        $totalDebits = 0;
        $totalCredits = 0;

        foreach ($accounts as $account) {
            $row = $balanceMap->get($account->id);
            $debits = $row ? (int) $row->total_debit : 0;
            $credits = $row ? (int) $row->total_credit : 0;

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
