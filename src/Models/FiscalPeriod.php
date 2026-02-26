<?php

declare(strict_types=1);

namespace App\Accounting\Models;

use App\Accounting\Enums\AccountCategory;
use App\Accounting\Exceptions\FiscalPeriodOverlapException;
use App\Accounting\Exceptions\PeriodClosedException;
use App\Accounting\Transaction;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Money\Currency;
use Money\Money;

class FiscalPeriod extends Model
{
    protected $table = 'accounting_fiscal_periods';

    protected $fillable = [
        'name',
        'start_date',
        'end_date',
        'status',
        'closed_at',
        'closed_by',
        'closing_transaction_group',
    ];

    protected $casts = [
        'start_date' => 'date',
        'end_date' => 'date',
        'closed_at' => 'datetime',
    ];

    /**
     * Check if a date falls within a closed fiscal period.
     * Returns the closed period if found, null otherwise.
     */
    public static function getClosedPeriodForDate(Carbon $date): ?self
    {
        return static::where('status', 'closed')
            ->where('start_date', '<=', $date)
            ->where('end_date', '>=', $date)
            ->first();
    }

    /**
     * Validate that a date is not in a closed period.
     * Throws PeriodClosedException if it is.
     */
    public static function validateDateNotClosed(Carbon $date): void
    {
        $period = static::getClosedPeriodForDate($date);
        if ($period) {
            throw PeriodClosedException::forDate(
                $date->toDateString(),
                $period->name
            );
        }
    }

    /**
     * Check if this period overlaps with any existing period.
     */
    public function hasOverlap(): bool
    {
        $query = static::where(function (Builder $q) {
            $q->where(function (Builder $q2) {
                $q2->where('start_date', '<=', $this->start_date)
                   ->where('end_date', '>=', $this->start_date);
            })->orWhere(function (Builder $q2) {
                $q2->where('start_date', '<=', $this->end_date)
                   ->where('end_date', '>=', $this->end_date);
            })->orWhere(function (Builder $q2) {
                $q2->where('start_date', '>=', $this->start_date)
                   ->where('end_date', '<=', $this->end_date);
            });
        });

        if ($this->exists) {
            $query->where('id', '!=', $this->id);
        }

        return $query->exists();
    }

    /**
     * Close this fiscal period.
     * Generates closing entries to zero out Income/Expense into Retained Earnings.
     */
    public function close(Account $retainedEarningsAccount, ?string $closedBy = null): void
    {
        if ($this->status === 'closed') {
            return;
        }

        DB::transaction(function () use ($retainedEarningsAccount, $closedBy) {
            $closingGroupUuid = $this->generateClosingEntries($retainedEarningsAccount);

            $this->update([
                'status' => 'closed',
                'closed_at' => Carbon::now(),
                'closed_by' => $closedBy,
                'closing_transaction_group' => $closingGroupUuid,
            ]);
        });
    }

    /**
     * Reopen this fiscal period.
     * Reverses closing entries and sets status back to open.
     */
    public function reopen(): void
    {
        if ($this->status === 'open') {
            return;
        }

        DB::transaction(function () {
            // Reverse closing entries if they exist
            if ($this->closing_transaction_group) {
                $closingEntries = JournalEntry::where('transaction_group', $this->closing_transaction_group)->get();
                foreach ($closingEntries as $entry) {
                    if (!$entry->is_reversed) {
                        $entry->reverse("REOPEN: Reversing closing entry for period {$this->name}");
                    }
                }
            }

            $this->update([
                'status' => 'open',
                'closed_at' => null,
                'closed_by' => null,
                'closing_transaction_group' => null,
            ]);
        });
    }

    public function isOpen(): bool
    {
        return $this->status === 'open';
    }

    public function isClosed(): bool
    {
        return $this->status === 'closed';
    }

    /**
     * Scope to get open periods.
     */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->where('status', 'open');
    }

    /**
     * Scope to get closed periods.
     */
    public function scopeClosed(Builder $query): Builder
    {
        return $query->where('status', 'closed');
    }

    /**
     * Generate monthly periods for a date range.
     */
    public static function generateMonthly(Carbon $from, Carbon $to): array
    {
        $periods = [];
        $current = $from->copy()->startOfMonth();

        while ($current->lt($to)) {
            $start = $current->copy();
            $end = $current->copy()->endOfMonth();

            if ($end->gt($to)) {
                $end = $to->copy();
            }

            $period = new static([
                'name' => $start->format('F Y'),
                'start_date' => $start,
                'end_date' => $end,
                'status' => 'open',
            ]);

            if (!$period->hasOverlap()) {
                $period->save();
                $periods[] = $period;
            }

            $current->addMonth();
        }

        return $periods;
    }

    /**
     * Generate closing entries for Income and Expense accounts.
     * Returns the transaction group UUID.
     */
    private function generateClosingEntries(Account $retainedEarningsAccount): ?string
    {
        // Find all income and expense account types
        $incomeExpenseTypes = AccountType::whereIn('type', [
            AccountCategory::INCOME->value,
            AccountCategory::EXPENSE->value,
        ])->get();

        if ($incomeExpenseTypes->isEmpty()) {
            return null;
        }

        $accounts = Account::whereIn('account_type_id', $incomeExpenseTypes->pluck('id'))
            ->where('is_active', true)
            ->get();

        if ($accounts->isEmpty()) {
            return null;
        }

        $transaction = Transaction::newDoubleEntryTransactionGroup();
        $hasEntries = false;
        $currency = new Currency($retainedEarningsAccount->currency);
        $postDate = $this->end_date->copy();

        foreach ($accounts as $account) {
            $balance = $account->getBalanceOn($this->end_date);
            $balanceAmount = (int) $balance->getAmount();

            if ($balanceAmount === 0) {
                continue;
            }

            $accountType = $account->accountType;
            $isDebitNormal = $accountType->type->isDebitNormal();

            // To zero out the account, we need the opposite entry
            if ($isDebitNormal) {
                // Expense accounts (debit normal): credit to zero, debit retained earnings
                if ($balanceAmount > 0) {
                    $transaction->addTransaction(
                        $account,
                        'credit',
                        new Money($balanceAmount, $currency),
                        "Closing entry: {$account->name}",
                        null,
                        $postDate
                    );
                    $transaction->addTransaction(
                        $retainedEarningsAccount,
                        'debit',
                        new Money($balanceAmount, $currency),
                        "Closing entry: {$account->name}",
                        null,
                        $postDate
                    );
                } else {
                    $absAmount = abs($balanceAmount);
                    $transaction->addTransaction(
                        $account,
                        'debit',
                        new Money($absAmount, $currency),
                        "Closing entry: {$account->name}",
                        null,
                        $postDate
                    );
                    $transaction->addTransaction(
                        $retainedEarningsAccount,
                        'credit',
                        new Money($absAmount, $currency),
                        "Closing entry: {$account->name}",
                        null,
                        $postDate
                    );
                }
            } else {
                // Income accounts (credit normal): debit to zero, credit retained earnings
                if ($balanceAmount > 0) {
                    $transaction->addTransaction(
                        $account,
                        'debit',
                        new Money($balanceAmount, $currency),
                        "Closing entry: {$account->name}",
                        null,
                        $postDate
                    );
                    $transaction->addTransaction(
                        $retainedEarningsAccount,
                        'credit',
                        new Money($balanceAmount, $currency),
                        "Closing entry: {$account->name}",
                        null,
                        $postDate
                    );
                } else {
                    $absAmount = abs($balanceAmount);
                    $transaction->addTransaction(
                        $account,
                        'credit',
                        new Money($absAmount, $currency),
                        "Closing entry: {$account->name}",
                        null,
                        $postDate
                    );
                    $transaction->addTransaction(
                        $retainedEarningsAccount,
                        'debit',
                        new Money($absAmount, $currency),
                        "Closing entry: {$account->name}",
                        null,
                        $postDate
                    );
                }
            }
            $hasEntries = true;
        }

        if (!$hasEntries) {
            return null;
        }

        return $transaction->commit();
    }

    protected static function boot(): void
    {
        parent::boot();

        static::creating(function (self $period): void {
            if ($period->hasOverlap()) {
                throw FiscalPeriodOverlapException::forDates(
                    $period->start_date->toDateString(),
                    $period->end_date->toDateString()
                );
            }
        });
    }
}
