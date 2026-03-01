<?php

declare(strict_types=1);

namespace App\Accounting\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * A journal entry groups one or more LedgerEntry rows into a balanced transaction.
 *
 * Primary key is a UUID string (not auto-increment) to allow safe generation
 * before the record is persisted.
 *
 * Lifecycle:
 *   draft (is_posted=false) → post() → posted (is_posted=true)
 *   posted → unpost() → draft
 *   posted → reverse() → new posted reversal JournalEntry
 *   posted → void()    → new posted void JournalEntry (same date, VOID: prefix)
 *
 * All state transitions are wrapped in a database transaction.
 *
 * @property string   $id
 * @property \Carbon\Carbon $date
 * @property string|null $reference_number
 * @property string|null $memo
 * @property bool     $is_posted
 */
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
            if (!isset($entry->id) || $entry->id === '') {
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
     * Resequences running balances and recalculates affected account cached balances.
     *
     * Wrapped in a DB transaction. Bulk-sets is_posted before the resequence pass
     * so that multi-line entries hitting the same account use the correct cumulative
     * balance (fixes C2: stale running_balance for same-account multi-line entries).
     */
    public function post(): self
    {
        if ($this->is_posted) {
            return $this;
        }

        DB::transaction(function () {
            // Mark the journal entry posted
            $this->is_posted = true;
            $this->saveQuietly();

            // Collect entries and unique account IDs without a second query
            $entries = $this->ledgerEntries()->with('account')->get();
            $accountIds = $entries->pluck('account_id')->unique()->values()->all();

            // Bulk-set is_posted = true on all ledger entries in one query so
            // subsequent balance queries include them before we resequence
            DB::table('accounting_ledger_entries')
                ->where('journal_entry_id', $this->id)
                ->update(['is_posted' => true]);

            // Resequence running balances and refresh cached balances per account
            foreach ($accountIds as $accountId) {
                $account = $entries->where('account_id', $accountId)->first()?->account;
                if ($account) {
                    $account->resequenceRunningBalances();
                    $account->recalculateBalance();
                }
            }
        });

        return $this;
    }

    /**
     * Unpost this journal entry and all its ledger entries.
     * Resequences remaining posted entries to fix running balances on affected accounts.
     */
    public function unpost(): self
    {
        if (!$this->is_posted) {
            return $this;
        }

        DB::transaction(function () {
            $this->is_posted = false;
            $this->saveQuietly();

            // Collect account IDs before bulk-updating
            $accountIds = $this->ledgerEntries()->pluck('account_id')->unique()->values()->all();

            // Bulk-unpost all ledger entries; running_balance reset handled below
            DB::table('accounting_ledger_entries')
                ->where('journal_entry_id', $this->id)
                ->update(['is_posted' => false, 'running_balance' => 0]);

            // Resequence remaining posted entries and recalculate cached balances
            foreach ($accountIds as $accountId) {
                $account = Account::find($accountId);
                if ($account) {
                    $account->resequenceRunningBalances();
                    $account->recalculateBalance();
                }
            }
        });

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

        return DB::transaction(function () use ($memo) {
            $reversalMemo = $memo ?? "Reversal of {$this->reference_number}";

            $reversal = self::create([
                'date' => now()->toDateString(),
                'reference_number' => null,
                'memo' => $reversalMemo,
                'is_posted' => true,
            ]);

            // Eager-load accounts to avoid N+1 per reversal entry
            $originalEntries = $this->ledgerEntries()->with('account')->get();

            foreach ($originalEntries as $entry) {
                $reversal->ledgerEntries()->create([
                    'account_id' => $entry->account_id,
                    'debit' => $entry->credit,  // swap
                    'credit' => $entry->debit,  // swap
                    'currency' => $entry->currency,
                    'memo' => $reversalMemo,
                    'post_date' => now(),
                ]);
            }

            // Collect all affected account IDs from both the original and reversal entries
            $originalAccountIds = $originalEntries->pluck('account_id')->unique()->values()->all();
            $reversalAccountIds = $reversal->ledgerEntries()->pluck('account_id')->unique()->values()->all();
            $allAccountIds = array_unique(array_merge($originalAccountIds, $reversalAccountIds));

            foreach ($allAccountIds as $accountId) {
                $account = Account::find($accountId);
                if ($account) {
                    $account->resequenceRunningBalances();
                    $account->recalculateBalance();
                }
            }

            return $reversal;
        });
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

        return DB::transaction(function () {
            $voidMemo = "VOID: {$this->memo}";

            $void = self::create([
                'date' => $this->date,
                'reference_number' => null,
                'memo' => $voidMemo,
                'is_posted' => true,
            ]);

            // Eager-load accounts to avoid N+1 per void entry
            $originalEntries = $this->ledgerEntries()->with('account')->get();

            foreach ($originalEntries as $entry) {
                $void->ledgerEntries()->create([
                    'account_id' => $entry->account_id,
                    'debit' => $entry->credit,
                    'credit' => $entry->debit,
                    'currency' => $entry->currency,
                    'memo' => $voidMemo,
                    'post_date' => $entry->post_date,
                ]);
            }

            // Collect all affected account IDs from both the original and void entries
            $originalAccountIds = $originalEntries->pluck('account_id')->unique()->values()->all();
            $voidAccountIds = $void->ledgerEntries()->pluck('account_id')->unique()->values()->all();
            $allAccountIds = array_unique(array_merge($originalAccountIds, $voidAccountIds));

            foreach ($allAccountIds as $accountId) {
                $account = Account::find($accountId);
                if ($account) {
                    $account->resequenceRunningBalances();
                    $account->recalculateBalance();
                }
            }

            return $void;
        });
    }
}
