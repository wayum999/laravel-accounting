<?php

declare(strict_types=1);

namespace App\Accounting\Models;

use App\Accounting\Exceptions\ImmutableEntryException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class LedgerEntry extends Model
{
    protected $table = 'accounting_ledger_entries';

    protected $fillable = [
        'journal_entry_id',
        'account_id',
        'debit',
        'credit',
        'running_balance',
        'is_posted',
        'currency',
        'memo',
        'post_date',
        'tags',
        'ledgerable_type',
        'ledgerable_id',
    ];

    protected $casts = [
        'debit' => 'integer',
        'credit' => 'integer',
        'running_balance' => 'integer',
        'is_posted' => 'boolean',
        'post_date' => 'datetime',
        'tags' => 'array',
    ];

    // -------------------------------------------------------
    // Boot
    // -------------------------------------------------------

    protected static function boot(): void
    {
        parent::boot();

        static::creating(function (LedgerEntry $entry) {
            // Default is_posted to true if not explicitly set
            if (!isset($entry->attributes['is_posted'])) {
                $entry->is_posted = true;
            }

            // Only compute running_balance for posted entries
            if ($entry->is_posted) {
                $lastEntry = self::where('account_id', $entry->account_id)
                    ->where('is_posted', true)
                    ->latest('id')
                    ->first();

                $lastBalance = $lastEntry?->running_balance ?? 0;

                $account = Account::find($entry->account_id);

                if ($account && $account->isDebitNormal()) {
                    $entry->running_balance = $lastBalance + $entry->debit - $entry->credit;
                } else {
                    $entry->running_balance = $lastBalance + $entry->credit - $entry->debit;
                }
            } else {
                $entry->running_balance = 0;
            }
        });

        static::created(function (LedgerEntry $entry) {
            // Only recalculate balance for posted entries
            if ($entry->is_posted) {
                $entry->account?->recalculateBalance();
            }
        });

        static::updating(function (LedgerEntry $entry) {
            $dirty = $entry->getDirty();
            $allowedFields = ['is_posted', 'running_balance'];
            $disallowedChanges = array_diff(array_keys($dirty), $allowedFields);

            if (!empty($disallowedChanges)) {
                throw new ImmutableEntryException('update');
            }
        });

        static::deleting(function (LedgerEntry $entry) {
            throw new ImmutableEntryException('delete');
        });
    }

    // -------------------------------------------------------
    // Relationships
    // -------------------------------------------------------

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'account_id');
    }

    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class, 'journal_entry_id');
    }

    /**
     * Polymorphic relationship to any model this entry references.
     */
    public function ledgerable(): MorphTo
    {
        return $this->morphTo();
    }

    // -------------------------------------------------------
    // Reference helpers
    // -------------------------------------------------------

    /**
     * @deprecated Pass ledgerable_type/ledgerable_id at creation time instead.
     *             Ledger entries are immutable after creation.
     *
     * @throws ImmutableEntryException
     */
    public function referencesModel(Model $model): never
    {
        throw new ImmutableEntryException('update');
    }

    /**
     * Resolve the referenced model, or null if none set.
     */
    public function getReferencedModel(): ?Model
    {
        if (!$this->ledgerable_type || !$this->ledgerable_id) {
            return null;
        }

        return $this->ledgerable;
    }
}
