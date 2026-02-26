<?php

declare(strict_types=1);

namespace App\Accounting\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;
use App\Accounting\Enums\NonPostingStatus;
use App\Accounting\Exceptions\NonPostingAlreadyConverted;
use App\Accounting\ModelTraits\HasReferencedObject;
use App\Accounting\Transaction;

class NonPostingTransaction extends Model
{
    use SoftDeletes;
    use HasReferencedObject;

    protected $table = 'accounting_non_posting_transactions';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected $casts = [
        'total_amount' => 'int',
        'metadata' => 'array',
        'due_date' => 'datetime',
        'status' => NonPostingStatus::class,
    ];

    protected static function boot(): void
    {
        parent::boot();

        static::creating(function (self $transaction): void {
            $transaction->id = Str::uuid()->toString();
            if (! $transaction->status) {
                $transaction->status = NonPostingStatus::DRAFT;
            }
        });
    }

    public function lineItems(): HasMany
    {
        return $this->hasMany(NonPostingLineItem::class)->orderBy('sort_order');
    }

    public function morphed(): MorphTo
    {
        return $this->morphTo();
    }



    /**
     * Recalculate total_amount from line items.
     */
    public function recalculateTotal(): void
    {
        $this->total_amount = $this->lineItems()->sum('amount');
        $this->save();
    }

    /**
     * Whether this transaction has been converted to posting entries.
     */
    public function isConverted(): bool
    {
        return $this->converted_to_group !== null;
    }

    /**
     * Convert this non-posting transaction into real journal entries.
     *
     * @param array<int, array{account: Account, method: string}> $accountMapping
     *        Maps line item index to an account and debit/credit method.
     *        Example: [0 => ['account' => $cashAccount, 'method' => 'debit'], ...]
     * @return string The transaction_group UUID of the created journal entries
     * @throws NonPostingAlreadyConverted
     */
    public function convertToPosting(array $accountMapping): string
    {
        if ($this->isConverted()) {
            throw new NonPostingAlreadyConverted();
        }

        if (! $this->status->canConvert()) {
            throw new NonPostingAlreadyConverted(
                "Cannot convert a transaction with status '{$this->status->value}'."
            );
        }

        $transaction = Transaction::newDoubleEntryTransactionGroup();

        foreach ($accountMapping as $mapping) {
            $transaction->addTransaction(
                $mapping['account'],
                $mapping['method'],
                new \Money\Money($mapping['amount'], new \Money\Currency($this->currency)),
                $mapping['memo'] ?? $this->description,
            );
        }

        $groupUuid = $transaction->commit();

        $this->update([
            'status' => NonPostingStatus::CONVERTED,
            'converted_to_group' => $groupUuid,
        ]);

        return $groupUuid;
    }
}
