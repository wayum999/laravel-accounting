<?php

declare(strict_types=1);

namespace App\Accounting\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class NonPostingLineItem extends Model
{
    protected $table = 'accounting_non_posting_line_items';

    public $incrementing = false;

    protected $fillable = [
        'non_posting_transaction_id',
        'account_id',
        'description',
        'quantity',
        'unit_price',
        'amount',
        'ref_class',
        'ref_class_id',
        'sort_order',
    ];

    protected $casts = [
        'quantity' => 'decimal:4',
        'unit_price' => 'int',
        'amount' => 'int',
        'sort_order' => 'int',
    ];

    protected static function boot(): void
    {
        parent::boot();

        static::creating(function (self $lineItem): void {
            $lineItem->id = Str::uuid()->toString();
        });

        static::saving(function (self $lineItem): void {
            // Auto-calculate amount if quantity and unit_price are set
            if ($lineItem->quantity && $lineItem->unit_price) {
                $lineItem->amount = (int) round((float) $lineItem->quantity * $lineItem->unit_price);
            }
        });

        static::saved(function (self $lineItem): void {
            $lineItem->nonPostingTransaction?->recalculateTotal();
        });

        static::deleted(function (self $lineItem): void {
            $lineItem->nonPostingTransaction?->recalculateTotal();
        });
    }

    public function nonPostingTransaction(): BelongsTo
    {
        return $this->belongsTo(NonPostingTransaction::class);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    /**
     * Retrieve the referenced object.
     */
    public function getReferencedObject(): ?Model
    {
        if (! $this->ref_class) {
            return null;
        }

        $class = new $this->ref_class;
        return $class->find($this->ref_class_id);
    }

    /**
     * Set the referenced object.
     */
    public function referencesObject(Model $object): self
    {
        $this->update([
            'ref_class' => $object::class,
            'ref_class_id' => $object->id,
        ]);

        return $this;
    }
}
