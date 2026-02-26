<?php

declare(strict_types=1);

namespace App\Accounting\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;
use App\Accounting\ModelTraits\HasReferencedObject;

class JournalEntry extends Model
{
    use SoftDeletes;
    use HasReferencedObject;

    protected $table = 'accounting_journal_entries';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected $casts = [
        'post_date' => 'datetime',
        'tags' => 'array',
        'debit' => 'int',
        'credit' => 'int',
        'ref_class_id' => 'int',
        'is_posted' => 'boolean',
        'is_reversed' => 'boolean',
    ];

    protected static function boot(): void
    {
        parent::boot();

        static::creating(function (self $entry): void {
            $entry->id = Str::uuid()->toString();
        });

        static::deleted(function (self $entry): void {
            $entry->account?->resetCurrentBalances();
        });
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }



    /**
     * Reverse this journal entry by creating an equal-and-opposite entry.
     */
    public function reverse(?string $memo = null, ?Carbon $postDate = null): self
    {
        if ($this->is_reversed) {
            throw \App\Accounting\Exceptions\TransactionAlreadyReversedException::forEntry($this->id);
        }

        $reversalEntry = $this->account->journalEntries()->create([
            'debit' => $this->credit,
            'credit' => $this->debit,
            'currency' => $this->currency,
            'memo' => $memo ?? "REVERSAL: {$this->memo}",
            'post_date' => $postDate ?? Carbon::now(),
            'transaction_group' => $this->transaction_group,
            'ref_class' => $this->ref_class,
            'ref_class_id' => $this->ref_class_id,
            'is_posted' => true,
            'reversal_of' => $this->id,
        ]);

        $this->update([
            'is_reversed' => true,
            'reversed_by' => $reversalEntry->id,
        ]);

        $this->account->resetCurrentBalances();

        return $reversalEntry;
    }

    /**
     * Void this journal entry (reverse with same post_date and VOID memo).
     */
    public function void(): self
    {
        return $this->reverse(
            "VOID: {$this->memo}",
            $this->post_date
        );
    }

    /**
     * Get the entry that reversed this one.
     */
    public function reversedByEntry(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reversed_by');
    }

    /**
     * Get the original entry that this one reverses.
     */
    public function reversalOfEntry(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reversal_of');
    }
}
