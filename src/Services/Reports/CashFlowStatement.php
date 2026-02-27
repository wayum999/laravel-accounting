<?php

declare(strict_types=1);

namespace App\Accounting\Services\Reports;

use App\Accounting\Enums\AccountSubType;
use App\Accounting\Enums\AccountType;
use App\Accounting\Models\Account;
use App\Accounting\Models\LedgerEntry;
use Carbon\Carbon;

class CashFlowStatement
{
    /**
     * Generate a cash flow statement using the direct method.
     *
     * Categorizes cash flows by the type of the contra-account in each transaction:
     * - Operating: income/expense accounts (day-to-day business)
     * - Investing: asset accounts (equipment purchases, etc.)
     * - Financing: liability/equity accounts (loans, owner investment)
     *
     * @param  Account|null  $cashAccount  Specific cash/bank account. If null, uses all bank-type assets.
     * @return array{operating: array, investing: array, financing: array, net_cash_flow: int, beginning_balance: int, ending_balance: int, period_start: string, period_end: string, currency: string}
     */
    public static function generate(Carbon $from, Carbon $to, ?Account $cashAccount = null, string $currency = 'USD'): array
    {
        // Determine which accounts are "cash" accounts
        $cashAccountIds = self::getCashAccountIds($cashAccount, $currency);

        if (empty($cashAccountIds)) {
            return self::emptyReport($from, $to, $currency);
        }

        // Get all posted ledger entries hitting cash accounts in the period
        $cashEntries = LedgerEntry::whereIn('account_id', $cashAccountIds)
            ->where('is_posted', true)
            ->whereBetween('post_date', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])
            ->with('journalEntry.ledgerEntries.account')
            ->get();

        $operating = [];
        $investing = [];
        $financing = [];

        foreach ($cashEntries as $cashEntry) {
            // Net cash effect: debit = cash in, credit = cash out
            $cashEffect = $cashEntry->debit - $cashEntry->credit;

            if ($cashEffect === 0) {
                continue;
            }

            // Find the contra-account(s) in the same journal entry
            $contraType = self::determineContraType($cashEntry);

            $detail = [
                'journal_entry_id' => $cashEntry->journal_entry_id,
                'memo' => $cashEntry->memo,
                'amount' => $cashEffect,
                'date' => $cashEntry->post_date?->toDateString(),
            ];

            match ($contraType) {
                'operating' => $operating[] = $detail,
                'investing' => $investing[] = $detail,
                'financing' => $financing[] = $detail,
            };
        }

        $totalOperating = array_sum(array_column($operating, 'amount'));
        $totalInvesting = array_sum(array_column($investing, 'amount'));
        $totalFinancing = array_sum(array_column($financing, 'amount'));

        // Beginning and ending cash balances
        $beginningBalance = self::getCashBalance($cashAccountIds, $from->copy()->subDay());
        $endingBalance = self::getCashBalance($cashAccountIds, $to);

        return [
            'operating' => $operating,
            'investing' => $investing,
            'financing' => $financing,
            'total_operating' => $totalOperating,
            'total_investing' => $totalInvesting,
            'total_financing' => $totalFinancing,
            'net_cash_flow' => $totalOperating + $totalInvesting + $totalFinancing,
            'beginning_balance' => $beginningBalance,
            'ending_balance' => $endingBalance,
            'period_start' => $from->toDateString(),
            'period_end' => $to->toDateString(),
            'currency' => $currency,
        ];
    }

    /**
     * Get the IDs of cash/bank accounts.
     */
    private static function getCashAccountIds(?Account $cashAccount, string $currency): array
    {
        if ($cashAccount) {
            return [$cashAccount->id];
        }

        return Account::where('type', AccountType::ASSET)
            ->where('sub_type', AccountSubType::BANK)
            ->where('currency', $currency)
            ->where('is_active', true)
            ->pluck('id')
            ->toArray();
    }

    /**
     * Determine the cash flow category based on the contra-account type.
     */
    private static function determineContraType(LedgerEntry $cashEntry): string
    {
        if (!$cashEntry->journalEntry) {
            return 'operating';
        }

        // Find contra-entries (entries in the same journal entry that aren't this cash entry)
        $contraEntries = $cashEntry->journalEntry->ledgerEntries
            ->where('id', '!=', $cashEntry->id);

        if ($contraEntries->isEmpty()) {
            return 'operating';
        }

        // Use the type of the first contra-account to categorize
        $contraAccount = $contraEntries->first()->account;

        if (!$contraAccount) {
            return 'operating';
        }

        return match ($contraAccount->type) {
            AccountType::INCOME, AccountType::EXPENSE => 'operating',
            AccountType::ASSET => 'investing',
            AccountType::LIABILITY, AccountType::EQUITY => 'financing',
        };
    }

    /**
     * Calculate total cash balance across given account IDs as of a date.
     */
    private static function getCashBalance(array $accountIds, Carbon $asOf): int
    {
        $debits = (int) LedgerEntry::whereIn('account_id', $accountIds)
            ->where('is_posted', true)
            ->where('post_date', '<=', $asOf->copy()->endOfDay())
            ->sum('debit');

        $credits = (int) LedgerEntry::whereIn('account_id', $accountIds)
            ->where('is_posted', true)
            ->where('post_date', '<=', $asOf->copy()->endOfDay())
            ->sum('credit');

        // Cash is an asset (debit-normal)
        return $debits - $credits;
    }

    private static function emptyReport(Carbon $from, Carbon $to, string $currency): array
    {
        return [
            'operating' => [],
            'investing' => [],
            'financing' => [],
            'total_operating' => 0,
            'total_investing' => 0,
            'total_financing' => 0,
            'net_cash_flow' => 0,
            'beginning_balance' => 0,
            'ending_balance' => 0,
            'period_start' => $from->toDateString(),
            'period_end' => $to->toDateString(),
            'currency' => $currency,
        ];
    }
}
