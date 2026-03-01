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
use Illuminate\Support\Facades\DB;
use Money\Currency;
use Money\Money;

/**
 * Represents a chart-of-accounts entry in the double-entry accounting system.
 *
 * Balance reads:
 *   - `$account->balance`         → cached Money value (may be stale between resequences)
 *   - `$account->getBalance()`    → live Money value computed from ledger entries
 *
 * Mutations must go through TransactionBuilder to maintain the double-entry invariant.
 * Direct debit()/credit() helpers are available for simple one-sided entries but bypass
 * the double-entry constraint; prefer TransactionBuilder in new code.
 *
 * @property int         $id
 * @property int|null    $parent_id
 * @property string      $name
 * @property string|null $code
 * @property AccountType $type
 * @property AccountSubType|null $sub_type
 * @property string|null $description
 * @property string      $currency
 * @property int         $cached_balance
 * @property bool        $is_active
 * @property string|null $accountable_type
 * @property int|null    $accountable_id
 */
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

    /**
     * Returns the cached (potentially stale) balance as a Money object.
     *
     * This is the Eloquent accessor for the `balance` virtual property.
     * The underlying `cached_balance` column stores an integer (cents); this
     * accessor wraps it in a Money object for a consistent API.
     *
     * WARNING: this value may be stale between a post() and the next
     * recalculateBalance() call. Use getBalance() when you need a live
     * value computed from ledger entries.
     *
     * @see getBalance() for a live, accurate balance
     */
    public function getBalanceAttribute(): Money
    {
        $amount = $this->attributes['cached_balance'] ?? 0;
        $currency = $this->attributes['currency'] ?? config('accounting.base_currency', 'USD');

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

    /**
     * @throws \LogicException if account type is not set
     */
    public function isDebitNormal(): bool
    {
        if ($this->type === null) {
            throw new \LogicException(
                "Account [{$this->id}] has no type set; cannot determine normal balance direction."
            );
        }

        return $this->type->isDebitNormal();
    }

    public function isCreditNormal(): bool
    {
        return !$this->isDebitNormal();
    }

    // -------------------------------------------------------
    // Balance calculation (from ledger entries)
    // -------------------------------------------------------

    /**
     * Compute balance from all ledger entries using a single query.
     * Respects normal balance direction. Always returns positive when
     * the account is in its normal state.
     */
    public function getBalance(): Money
    {
        $row = $this->ledgerEntries()
            ->where('is_posted', true)
            ->selectRaw('SUM(debit) as total_debit, SUM(credit) as total_credit')
            ->first();

        $debits = $row ? (int) $row->total_debit : 0;
        $credits = $row ? (int) $row->total_credit : 0;

        $balance = $this->isDebitNormal()
            ? $debits - $credits
            : $credits - $debits;

        return new Money((string) $balance, new Currency($this->currency ?? config('accounting.base_currency', 'USD')));
    }

    public function getBalanceInDollars(): float
    {
        return (int) $this->getBalance()->getAmount() / 100;
    }

    /**
     * @deprecated Use getBalance() instead.
     */
    public function getCurrentBalance(): Money
    {
        return $this->getBalance();
    }

    /**
     * @deprecated Use getBalanceInDollars() instead.
     */
    public function getCurrentBalanceInDollars(): float
    {
        return $this->getBalanceInDollars();
    }

    /**
     * Balance as of a specific date (inclusive), using a single query.
     */
    public function getBalanceOn(Carbon $date): Money
    {
        $endOfDay = $date->copy()->endOfDay();

        $row = $this->ledgerEntries()
            ->where('is_posted', true)
            ->where('post_date', '<=', $endOfDay)
            ->selectRaw('SUM(debit) as total_debit, SUM(credit) as total_credit')
            ->first();

        $debits = $row ? (int) $row->total_debit : 0;
        $credits = $row ? (int) $row->total_credit : 0;

        $balance = $this->isDebitNormal()
            ? $debits - $credits
            : $credits - $debits;

        return new Money((string) $balance, new Currency($this->currency ?? config('accounting.base_currency', 'USD')));
    }

    public function getDebitBalanceOn(Carbon $date): Money
    {
        $amount = (int) $this->ledgerEntries()
            ->where('is_posted', true)
            ->where('post_date', '<=', $date->copy()->endOfDay())
            ->sum('debit');

        return new Money((string) $amount, new Currency($this->currency ?? config('accounting.base_currency', 'USD')));
    }

    public function getCreditBalanceOn(Carbon $date): Money
    {
        $amount = (int) $this->ledgerEntries()
            ->where('is_posted', true)
            ->where('post_date', '<=', $date->copy()->endOfDay())
            ->sum('credit');

        return new Money((string) $amount, new Currency($this->currency ?? config('accounting.base_currency', 'USD')));
    }

    // -------------------------------------------------------
    // Convenience posting methods (standalone entries)
    // -------------------------------------------------------

    /**
     * Post a debit to this account. Amount in cents or Money object.
     *
     * NOTE: This bypasses the double-entry invariant. Prefer TransactionBuilder
     * for new code to maintain a balanced journal.
     */
    public function debit(int|Money $amount, ?string $memo = null, ?Carbon $postDate = null, ?Model $reference = null): LedgerEntry
    {
        $cents = $amount instanceof Money ? (int) $amount->getAmount() : $amount;
        $currency = $amount instanceof Money ? $amount->getCurrency()->getCode() : ($this->currency ?? config('accounting.base_currency', 'USD'));

        return DB::transaction(function () use ($cents, $currency, $memo, $postDate, $reference) {
            $entry = $this->ledgerEntries()->create([
                'debit' => $cents,
                'credit' => 0,
                'currency' => $currency,
                'memo' => $memo,
                'post_date' => $postDate ?? now(),
                'ledgerable_type' => $reference ? $reference->getMorphClass() : null,
                'ledgerable_id' => $reference?->getKey(),
            ]);

            $this->resequenceRunningBalances();
            $this->recalculateBalance();

            return $entry;
        });
    }

    /**
     * Post a credit to this account. Amount in cents or Money object.
     *
     * NOTE: This bypasses the double-entry invariant. Prefer TransactionBuilder
     * for new code to maintain a balanced journal.
     */
    public function credit(int|Money $amount, ?string $memo = null, ?Carbon $postDate = null, ?Model $reference = null): LedgerEntry
    {
        $cents = $amount instanceof Money ? (int) $amount->getAmount() : $amount;
        $currency = $amount instanceof Money ? $amount->getCurrency()->getCode() : ($this->currency ?? config('accounting.base_currency', 'USD'));

        return DB::transaction(function () use ($cents, $currency, $memo, $postDate, $reference) {
            $entry = $this->ledgerEntries()->create([
                'debit' => 0,
                'credit' => $cents,
                'currency' => $currency,
                'memo' => $memo,
                'post_date' => $postDate ?? now(),
                'ledgerable_type' => $reference ? $reference->getMorphClass() : null,
                'ledgerable_id' => $reference?->getKey(),
            ]);

            $this->resequenceRunningBalances();
            $this->recalculateBalance();

            return $entry;
        });
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
            ->where('ledgerable_type', $model->getMorphClass())
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

    /**
     * Resequence running_balance on all posted ledger entries for this account,
     * ordered by (post_date, id). Uses a database lock to prevent race conditions
     * from concurrent writes.
     *
     * Must be called after any batch of entries is posted or unposted.
     */
    public function resequenceRunningBalances(): void
    {
        DB::transaction(function () {
            // Lock the account row to serialize concurrent resequencing
            DB::table($this->getTable())
                ->where('id', $this->id)
                ->lockForUpdate()
                ->first();

            $entries = $this->ledgerEntries()
                ->where('is_posted', true)
                ->orderBy('post_date')
                ->orderBy('id')
                ->get(['id', 'debit', 'credit']);

            $balance = 0;

            foreach ($entries as $entry) {
                $balance += $this->isDebitNormal()
                    ? (int) $entry->debit - (int) $entry->credit
                    : (int) $entry->credit - (int) $entry->debit;

                // Bypass Eloquent events; only updating a computed column
                DB::table('accounting_ledger_entries')
                    ->where('id', $entry->id)
                    ->update(['running_balance' => $balance]);
            }
        });
    }
}
