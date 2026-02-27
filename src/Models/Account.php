<?php

declare(strict_types=1);

namespace App\Accounting\Models;

use App\Accounting\Enums\AccountSubType;
use App\Accounting\Enums\AccountType;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Money\Currency;
use Money\Money;

class Account extends Model
{
    use SoftDeletes;

    protected $table = 'accounting_accounts';

    protected $fillable = [
        'parent_id',
        'name',
        'code',
        'type',
        'sub_type',
        'description',
        'currency',
        'cached_balance',
        'is_active',
        'accountable_type',
        'accountable_id',
    ];

    protected $casts = [
        'type' => AccountType::class,
        'sub_type' => AccountSubType::class,
        'is_active' => 'boolean',
        'cached_balance' => 'integer',
    ];

    // -------------------------------------------------------
    // Boot
    // -------------------------------------------------------

    protected static function boot(): void
    {
        parent::boot();

        static::creating(function (Account $account) {
            if (!isset($account->attributes['cached_balance'])) {
                $account->cached_balance = 0;
            }
        });
    }

    // -------------------------------------------------------
    // Relationships
    // -------------------------------------------------------

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    public function ledgerEntries(): HasMany
    {
        return $this->hasMany(LedgerEntry::class, 'account_id');
    }

    public function accountable(): MorphTo
    {
        return $this->morphTo();
    }

    // -------------------------------------------------------
    // Balance attribute accessor/mutator
    // -------------------------------------------------------

    public function getBalanceAttribute(): Money
    {
        $amount = $this->attributes['cached_balance'] ?? 0;
        $currency = $this->attributes['currency'] ?? 'USD';

        return new Money((string) $amount, new Currency($currency));
    }

    public function setBalanceAttribute(mixed $value): void
    {
        if ($value instanceof Money) {
            $this->attributes['cached_balance'] = (int) $value->getAmount();
            $this->attributes['currency'] = $value->getCurrency()->getCode();
        } elseif (is_null($value) || $value === false || $value === true) {
            $this->attributes['cached_balance'] = 0;
        } elseif (is_numeric($value)) {
            $this->attributes['cached_balance'] = (int) $value;
        } else {
            $this->attributes['cached_balance'] = 0;
        }
    }

    // -------------------------------------------------------
    // Normal balance helpers
    // -------------------------------------------------------

    public function isDebitNormal(): bool
    {
        return $this->type?->isDebitNormal() ?? true; // default to debit-normal if no type set
    }

    public function isCreditNormal(): bool
    {
        return !$this->isDebitNormal();
    }

    // -------------------------------------------------------
    // Balance calculation (from ledger entries)
    // -------------------------------------------------------

    /**
     * Compute balance from all ledger entries, respecting normal balance direction.
     * Always returns positive when the account is in its normal state.
     */
    public function getBalance(): Money
    {
        $debits = (int) $this->ledgerEntries()->where('is_posted', true)->sum('debit');
        $credits = (int) $this->ledgerEntries()->where('is_posted', true)->sum('credit');

        $balance = $this->isDebitNormal()
            ? $debits - $credits
            : $credits - $debits;

        return new Money((string) $balance, new Currency($this->currency ?? 'USD'));
    }

    public function getBalanceInDollars(): float
    {
        return (int) $this->getBalance()->getAmount() / 100;
    }

    public function getCurrentBalance(): Money
    {
        return $this->getBalance();
    }

    public function getCurrentBalanceInDollars(): float
    {
        return $this->getBalanceInDollars();
    }

    /**
     * Balance as of a specific date (inclusive).
     */
    public function getBalanceOn(Carbon $date): Money
    {
        $debits = (int) $this->ledgerEntries()
            ->where('is_posted', true)
            ->where('post_date', '<=', $date->copy()->endOfDay())
            ->sum('debit');

        $credits = (int) $this->ledgerEntries()
            ->where('is_posted', true)
            ->where('post_date', '<=', $date->copy()->endOfDay())
            ->sum('credit');

        $balance = $this->isDebitNormal()
            ? $debits - $credits
            : $credits - $debits;

        return new Money((string) $balance, new Currency($this->currency ?? 'USD'));
    }

    public function getDebitBalanceOn(Carbon $date): Money
    {
        $amount = (int) $this->ledgerEntries()
            ->where('is_posted', true)
            ->where('post_date', '<=', $date->copy()->endOfDay())
            ->sum('debit');

        return new Money((string) $amount, new Currency($this->currency ?? 'USD'));
    }

    public function getCreditBalanceOn(Carbon $date): Money
    {
        $amount = (int) $this->ledgerEntries()
            ->where('is_posted', true)
            ->where('post_date', '<=', $date->copy()->endOfDay())
            ->sum('credit');

        return new Money((string) $amount, new Currency($this->currency ?? 'USD'));
    }

    // -------------------------------------------------------
    // Convenience posting methods (standalone entries)
    // -------------------------------------------------------

    /**
     * Post a debit to this account. Amount in cents or Money object.
     */
    public function debit(int|Money $amount, ?string $memo = null, ?Carbon $postDate = null, ?Model $reference = null): LedgerEntry
    {
        $cents = $amount instanceof Money ? (int) $amount->getAmount() : $amount;
        $currency = $amount instanceof Money ? $amount->getCurrency()->getCode() : ($this->currency ?? 'USD');

        $entry = $this->ledgerEntries()->create([
            'debit' => $cents,
            'credit' => 0,
            'currency' => $currency,
            'memo' => $memo,
            'post_date' => $postDate ?? now(),
            'ledgerable_type' => $reference ? get_class($reference) : null,
            'ledgerable_id' => $reference?->getKey(),
        ]);

        $this->refresh();

        return $entry;
    }

    /**
     * Post a credit to this account. Amount in cents or Money object.
     */
    public function credit(int|Money $amount, ?string $memo = null, ?Carbon $postDate = null, ?Model $reference = null): LedgerEntry
    {
        $cents = $amount instanceof Money ? (int) $amount->getAmount() : $amount;
        $currency = $amount instanceof Money ? $amount->getCurrency()->getCode() : ($this->currency ?? 'USD');

        $entry = $this->ledgerEntries()->create([
            'debit' => 0,
            'credit' => $cents,
            'currency' => $currency,
            'memo' => $memo,
            'post_date' => $postDate ?? now(),
            'ledgerable_type' => $reference ? get_class($reference) : null,
            'ledgerable_id' => $reference?->getKey(),
        ]);

        $this->refresh();

        return $entry;
    }

    public function debitDollars(float $dollars, ?string $memo = null, ?Carbon $postDate = null): LedgerEntry
    {
        return $this->debit((int) round($dollars * 100), $memo, $postDate);
    }

    public function creditDollars(float $dollars, ?string $memo = null, ?Carbon $postDate = null): LedgerEntry
    {
        return $this->credit((int) round($dollars * 100), $memo, $postDate);
    }

    // -------------------------------------------------------
    // Increase / Decrease (non-accountant friendly)
    // -------------------------------------------------------

    /**
     * Increase this account's balance. Automatically selects debit or credit
     * based on the account type's normal balance direction.
     */
    public function increase(int $amount, ?string $memo = null, ?Carbon $postDate = null): LedgerEntry
    {
        return $this->isDebitNormal()
            ? $this->debit($amount, $memo, $postDate)
            : $this->credit($amount, $memo, $postDate);
    }

    /**
     * Decrease this account's balance. Automatically selects debit or credit
     * based on the account type's normal balance direction.
     */
    public function decrease(int $amount, ?string $memo = null, ?Carbon $postDate = null): LedgerEntry
    {
        return $this->isDebitNormal()
            ? $this->credit($amount, $memo, $postDate)
            : $this->debit($amount, $memo, $postDate);
    }

    public function increaseDollars(float $dollars, ?string $memo = null, ?Carbon $postDate = null): LedgerEntry
    {
        return $this->increase((int) round($dollars * 100), $memo, $postDate);
    }

    public function decreaseDollars(float $dollars, ?string $memo = null, ?Carbon $postDate = null): LedgerEntry
    {
        return $this->decrease((int) round($dollars * 100), $memo, $postDate);
    }

    // -------------------------------------------------------
    // Daily activity helpers
    // -------------------------------------------------------

    public function getDollarsDebitedToday(): float
    {
        return $this->getDollarsDebitedOn(Carbon::today());
    }

    public function getDollarsCreditedToday(): float
    {
        return $this->getDollarsCreditedOn(Carbon::today());
    }

    public function getDollarsDebitedOn(Carbon $date): float
    {
        $amount = (int) $this->ledgerEntries()
            ->where('is_posted', true)
            ->whereDate('post_date', $date->toDateString())
            ->sum('debit');

        return $amount / 100;
    }

    public function getDollarsCreditedOn(Carbon $date): float
    {
        $amount = (int) $this->ledgerEntries()
            ->where('is_posted', true)
            ->whereDate('post_date', $date->toDateString())
            ->sum('credit');

        return $amount / 100;
    }

    // -------------------------------------------------------
    // Query helpers
    // -------------------------------------------------------

    /**
     * Get ledger entries that reference a specific model via the ledgerable morph.
     */
    public function entriesReferencingModel(Model $model): HasMany
    {
        return $this->ledgerEntries()
            ->where('ledgerable_type', get_class($model))
            ->where('ledgerable_id', $model->getKey());
    }

    // -------------------------------------------------------
    // Balance maintenance
    // -------------------------------------------------------

    /**
     * Recompute cached_balance from all ledger entries.
     */
    public function recalculateBalance(): Money
    {
        $balance = $this->getBalance();
        $this->cached_balance = (int) $balance->getAmount();
        $this->saveQuietly();

        return $balance;
    }
}
