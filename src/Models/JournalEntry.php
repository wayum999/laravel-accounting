<?php

declare(strict_types=1);

namespace App\Accounting\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class JournalEntry extends Model
{
    use SoftDeletes;

    protected $table = 'accounting_journal_entries';

    public $incrementing = false;

    protected $guarded = ['id'];

    protected $casts = [
        'post_date' => 'datetime',
        'tags' => 'array',
        'debit' => 'int',
        'credit' => 'int',
        'ref_class_id' => 'int',
        'is_posted' => 'boolean',
    ];

    protected $fillable = [
        'account_id',
        'debit',
        'credit',
        'currency',
        'memo',
        'post_date',
        'tags',
        'ref_class',
        'ref_class_id',
        'transaction_group',
        'is_posted',
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
     * Associate this entry with a referenced Eloquent model.
     */
    public function referencesObject(Model $object): self
    {
        $this->update([
            'ref_class' => $object::class,
            'ref_class_id' => $object->id,
        ]);

        return $this;
    }

    /**
     * Retrieve the referenced Eloquent model.
     */
    public function getReferencedObject(): ?Model
    {
        if (! $this->ref_class) {
            return null;
        }

        $class = new $this->ref_class;
        return $class->find($this->ref_class_id);
    }

    public function setCurrency(string $currency): void
    {
        $this->currency = $currency;
    }
}
