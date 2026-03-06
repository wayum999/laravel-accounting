<?php

declare(strict_types=1);

namespace App\Accounting\Models;

use App\Accounting\Exceptions\ImmutableEntryException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * An immutable record of a single debit or credit against an Account.
 *
 * Entries are created via TransactionBuilder (preferred) or directly through
 * Account::debit()/credit() for simple one-sided entries.
 *
 * Immutability: once created, only `is_posted` may be changed (via
 * JournalEntry::post()/unpost()), and `running_balance` is managed internally
 * by Account::resequenceRunningBalances(). All other field changes throw
 * ImmutableEntryException.
 *
 * running_balance is set to 0 on creation and recomputed in a sequential,
 * lock-protected pass by Account::resequenceRunningBalances() after each batch
 * of posts or unpostings. This eliminates the TOCTOU race condition that occurs
 * when concurrent inserts query the last balance without a row lock.
 *
 * @property int         $id
 * @property string      $journal_entry_id
 * @property int         $account_id
 * @property int         $debit
 * @property int         $credit
 * @property int         $running_balance
 * @property bool        $is_posted
 * @property string      $currency
 * @property string|null $memo
 * @property \Carbon\Carbon|null $post_date
 * @property array|null  $tags
 * @property string|null $ledgerable_type
 * @property int|null    $ledgerable_id
 */
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

            // running_balance is always initialised to 0 on creation.
            // The correct sequential running balance is computed after the
            // full batch of entries is created, via Account::resequenceRunningBalances().
            // Computing it here would introduce a TOCTOU race condition under concurrent
            // inserts for the same account.
            $entry->running_balance = 0;
        });

        static::updating(function (LedgerEntry $entry) {
            $dirty = $entry->getDirty();
            // Only is_posted is allowed to be changed via Eloquent events.
            // running_balance is updated via direct DB queries in resequenceRunningBalances()
            // to bypass this guard, as it must always be set as part of a controlled batch.
            $allowedFields = ['is_posted'];
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
