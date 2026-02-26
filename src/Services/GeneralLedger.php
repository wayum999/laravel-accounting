<?php

declare(strict_types=1);

namespace App\Accounting\Services;

use Carbon\Carbon;
use Illuminate\Support\Collection;
use Money\Money;
use Money\Currency;
use App\Accounting\Models\Account;
use App\Accounting\Models\AccountType;

/**
 * General Ledger service.
 *
 * Provides per-account running balance views -- the classic GL report.
 * Each entry includes the running balance calculated respecting the account type.
 */
class GeneralLedger
{
    /**
     * Get ledger entries for a specific account with running balance.
     *
     * Each entry in the returned collection has a `running_balance` attribute (int, in cents)
     * calculated based on the account type's normal balance direction.
     */
    public static function forAccount(
        Account $account,
        ?Carbon $from = null,
        ?Carbon $to = null
    ): Collection {
        $query = $account->journalEntries()
            ->where('is_posted', true)
            ->orderBy('post_date')
            ->orderBy('created_at');

        if ($from) {
            $query->where('post_date', '>=', $from);
        }

        if ($to) {
            $query->where('post_date', '<=', $to);
        }

        $entries = $query->get();

        $isDebitNormal = $account->accountType?->type?->isDebitNormal() ?? true;
        $runningBalance = 0;

        // If we have a $from filter, calculate the opening balance
        if ($from) {
            $priorDebits = $account->journalEntries()
                ->where('is_posted', true)
                ->where('post_date', '<', $from)
                ->sum('debit');
            $priorCredits = $account->journalEntries()
                ->where('is_posted', true)
                ->where('post_date', '<', $from)
                ->sum('credit');

            $runningBalance = $isDebitNormal
                ? $priorDebits - $priorCredits
                : $priorCredits - $priorDebits;
        }

        return $entries->map(function ($entry) use ($isDebitNormal, &$runningBalance) {
            $change = $isDebitNormal
                ? (($entry->debit ?? 0) - ($entry->credit ?? 0))
                : (($entry->credit ?? 0) - ($entry->debit ?? 0));

            $runningBalance += $change;
            $entry->running_balance = $runningBalance;

            return $entry;
        });
    }

    /**
     * Get a summary of all accounts under an account type.
     * Returns a collection of accounts with their current balances.
     */
    public static function forAccountType(
        AccountType $accountType,
        ?Carbon $from = null,
        ?Carbon $to = null,
        ?string $currency = null
    ): Collection {
        $accounts = $accountType->accounts()
            ->when($currency, fn($q) => $q->where('currency', $currency))
            ->where('is_active', true)
            ->get();

        return $accounts->map(function ($account) use ($from, $to) {
            $entries = static::forAccount($account, $from, $to);
            $finalBalance = $entries->last()?->running_balance ?? 0;

            $account->computed_balance = $finalBalance;
            $account->entry_count = $entries->count();

            return $account;
        });
    }

    /**
     * Get the balance for an account as a Money object.
     */
    public static function accountBalance(
        Account $account,
        ?Carbon $asOf = null
    ): Money {
        if ($asOf) {
            return $account->getBalanceOn($asOf);
        }
        return $account->getBalance();
    }
}
