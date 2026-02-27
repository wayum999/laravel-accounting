<?php

declare(strict_types=1);

namespace App\Accounting\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class JournalEntry extends Model
{
    protected $table = 'accounting_journal_entries';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'date',
        'reference_number',
        'memo',
        'is_posted',
    ];

    protected $casts = [
        'date' => 'date',
        'is_posted' => 'boolean',
    ];

    // -------------------------------------------------------
    // Boot
    // -------------------------------------------------------

    protected static function boot(): void
    {
        parent::boot();

        static::creating(function (JournalEntry $entry) {
            if (empty($entry->id)) {
                $entry->id = (string) Str::uuid();
            }

            // Default is_posted to true if not explicitly set
            if (!isset($entry->attributes['is_posted'])) {
                $entry->is_posted = true;
            }
        });
    }

    // -------------------------------------------------------
    // Relationships
    // -------------------------------------------------------

    public function ledgerEntries(): HasMany
    {
        return $this->hasMany(LedgerEntry::class, 'journal_entry_id');
    }

    // -------------------------------------------------------
    // Balance checks
    // -------------------------------------------------------

    public function totalDebits(): int
    {
        return (int) $this->ledgerEntries()->sum('debit');
    }

    public function totalCredits(): int
    {
        return (int) $this->ledgerEntries()->sum('credit');
    }

    public function isBalanced(): bool
    {
        return $this->totalDebits() === $this->totalCredits();
    }

    // -------------------------------------------------------
    // Post / Unpost
    // -------------------------------------------------------

    /**
     * Post this journal entry and all its ledger entries.
     * Recalculates running balances and affected account balances.
     */
    public function post(): self
    {
        if ($this->is_posted) {
            return $this;
        }

        $this->is_posted = true;
        $this->saveQuietly();

        // Post each ledger entry and recompute its running balance
        foreach ($this->ledgerEntries()->get() as $entry) {
            $account = $entry->account;

            $lastEntry = LedgerEntry::where('account_id', $entry->account_id)
                ->where('is_posted', true)
                ->latest('id')
                ->first();

            $lastBalance = $lastEntry?->running_balance ?? 0;

            if ($account && $account->isDebitNormal()) {
                $entry->running_balance = $lastBalance + $entry->debit - $entry->credit;
            } else {
                $entry->running_balance = $lastBalance + $entry->credit - $entry->debit;
            }

            $entry->is_posted = true;
            $entry->save();
        }

        // Recalculate all affected accounts
        $accountIds = $this->ledgerEntries()->pluck('account_id')->unique();
        foreach ($accountIds as $accountId) {
            Account::find($accountId)?->recalculateBalance();
        }

        return $this;
    }

    /**
     * Unpost this journal entry and all its ledger entries.
     * Recalculates affected account balances.
     */
    public function unpost(): self
    {
        if (!$this->is_posted) {
            return $this;
        }

        $this->is_posted = false;
        $this->saveQuietly();

        foreach ($this->ledgerEntries()->get() as $entry) {
            $entry->is_posted = false;
            $entry->running_balance = 0;
            $entry->save();
        }

        // Recalculate all affected accounts
        $accountIds = $this->ledgerEntries()->pluck('account_id')->unique();
        foreach ($accountIds as $accountId) {
            Account::find($accountId)?->recalculateBalance();
        }

        return $this;
    }

    // -------------------------------------------------------
    // Reverse / Void
    // -------------------------------------------------------

    /**
     * Create a new journal entry that reverses this one (opposite debits/credits).
     *
     * @throws \LogicException if the journal entry is not posted
     */
    public function reverse(?string $memo = null): JournalEntry
    {
        if (!$this->is_posted) {
            throw new \LogicException('Cannot reverse an unposted journal entry. Post it first or delete the draft.');
        }

        $reversalMemo = $memo ?? "Reversal of {$this->reference_number}";

        $reversal = self::create([
            'date' => now()->toDateString(),
            'reference_number' => null,
            'memo' => $reversalMemo,
            'is_posted' => true,
        ]);

        foreach ($this->ledgerEntries as $entry) {
            $reversal->ledgerEntries()->create([
                'account_id' => $entry->account_id,
                'debit' => $entry->credit,  // swap
                'credit' => $entry->debit,  // swap
                'currency' => $entry->currency,
                'memo' => $reversalMemo,
                'post_date' => now(),
            ]);

            // Recalculate the affected account's balance
            $entry->account->recalculateBalance();
        }

        return $reversal;
    }

    /**
     * Void this journal entry (reverse with original date and VOID: prefix).
     *
     * @throws \LogicException if the journal entry is not posted
     */
    public function void(): JournalEntry
    {
        if (!$this->is_posted) {
            throw new \LogicException('Cannot void an unposted journal entry. Post it first or delete the draft.');
        }

        $voidMemo = "VOID: {$this->memo}";

        $void = self::create([
            'date' => $this->date,
            'reference_number' => null,
            'memo' => $voidMemo,
            'is_posted' => true,
        ]);

        foreach ($this->ledgerEntries as $entry) {
            $void->ledgerEntries()->create([
                'account_id' => $entry->account_id,
                'debit' => $entry->credit,
                'credit' => $entry->debit,
                'currency' => $entry->currency,
                'memo' => $voidMemo,
                'post_date' => $entry->post_date,
            ]);

            $entry->account->recalculateBalance();
        }

        return $void;
    }
}
