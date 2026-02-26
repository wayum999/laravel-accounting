<?php

declare(strict_types=1);

namespace App\Accounting\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Money\Money;
use Money\Currency;
use Carbon\Carbon;

class Account extends Model
{
    protected $table = 'accounting_accounts';

    protected $dates = [
        'deleted_at',
        'updated_at'
    ];

    protected $fillable = [
        'account_type_id',
        'number',
        'name',
        'balance',
        'currency',
        'morphed_type',
        'morphed_id',
        'is_active',
    ];

    protected $casts = [
        'balance' => 'int',
        'morphed_id' => 'int',
        'is_active' => 'boolean',
    ];

    protected static function boot(): void
    {
        parent::boot();

        static::creating(function (self $account): void {
            $account->balance = 0;
        });

        static::created(function (self $account): void {
            $account->resetCurrentBalances();
        });
    }

    public function morphed(): MorphTo
    {
        return $this->morphTo();
    }

    public function accountType(): BelongsTo
    {
        return $this->belongsTo(AccountType::class);
    }

    public function setCurrency(string $currency): void
    {
        $this->currency = $currency;
    }

    public function assignToAccountType(AccountType $accountType): void
    {
        $accountType->accounts()->save($this);
    }

    public function journalEntries(): HasMany
    {
        return $this->hasMany(JournalEntry::class);
    }

    public function resetCurrentBalances(): Money
    {
        if (empty($this->currency)) {
            $this->attributes['balance'] = 0;
            return new Money(0, new Currency('USD'));
        }

        if ($this->journalEntries()->exists()) {
            $this->balance = $this->getBalance();
            $this->save();
        } else {
            $this->balance = new Money(0, new Currency($this->currency));
            $this->save();
        }

        return $this->balance;
    }

    protected function getBalanceAttribute(mixed $value): Money
    {
        return new Money((int) $value, new Currency($this->currency));
    }

    protected function setBalanceAttribute(mixed $value): void
    {
        if ($value instanceof Money) {
            $this->attributes['balance'] = (int) $value->getAmount();
            $this->currency = $value->getCurrency()->getCode();
            return;
        }

        if (empty($this->currency)) {
            $this->currency = 'USD';
        }

        $amount = is_numeric($value) ? (int) $value : 0;
        $money = new Money($amount, new Currency($this->currency));

        $this->attributes['balance'] = (int) $money->getAmount();
    }

    // -------------------------------------------------------
    // Balance Calculation -- respects account type normal balance
    // -------------------------------------------------------

    /**
     * Determines if this account has a debit-normal balance.
     * Falls back to true (debit-normal) if no account type is assigned.
     */
    private function isDebitNormal(): bool
    {
        return $this->accountType?->type?->isDebitNormal() ?? true;
    }

    /**
     * Calculate balance from all journal entries, respecting account type.
     *
     * Debit-normal accounts (Asset, Expense): balance = sum(debits) - sum(credits)
     * Credit-normal accounts (Liability, Equity, Income): balance = sum(credits) - sum(debits)
     */
    public function getBalance(): Money
    {
        if (!$this->journalEntries()->exists()) {
            return new Money(0, new Currency($this->currency));
        }

        $debitTotal = $this->journalEntries()->sum('debit');
        $creditTotal = $this->journalEntries()->sum('credit');

        if ($this->isDebitNormal()) {
            $balance = $debitTotal - $creditTotal;
        } else {
            $balance = $creditTotal - $debitTotal;
        }

        return new Money($balance, new Currency($this->currency));
    }

    /**
     * Get balance as of a specific date.
     */
    public function getBalanceOn(Carbon $date): Money
    {
        $debitTotal = $this->journalEntries()
            ->where('post_date', '<=', $date)
            ->sum('debit') ?: 0;

        $creditTotal = $this->journalEntries()
            ->where('post_date', '<=', $date)
            ->sum('credit') ?: 0;

        if ($this->isDebitNormal()) {
            $balance = $debitTotal - $creditTotal;
        } else {
            $balance = $creditTotal - $debitTotal;
        }

        return new Money($balance, new Currency($this->currency));
    }

    public function getDebitBalanceOn(Carbon $date): Money
    {
        $balance = $this->journalEntries()
            ->where('post_date', '<=', $date)
            ->sum('debit') ?: 0;

        return new Money($balance, new Currency($this->currency));
    }

    public function getCreditBalanceOn(Carbon $date): Money
    {
        $balance = $this->journalEntries()
            ->where('post_date', '<=', $date)
            ->sum('credit') ?: 0;

        return new Money($balance, new Currency($this->currency));
    }

    public function getCurrentBalance(): Money
    {
        return $this->getBalanceOn(Carbon::now());
    }

    public function getCurrentBalanceInDollars(): float
    {
        return $this->getCurrentBalance()->getAmount() / 100;
    }

    public function getBalanceInDollars(): float
    {
        $amount = $this->getBalance()->getAmount();
        return round($amount / 100, 2);
    }

    public function transactionsReferencingObjectQuery(Model $object): HasMany
    {
        return $this->journalEntries()
            ->where('ref_class', $object::class)
            ->where('ref_class_id', $object->id);
    }

    // -------------------------------------------------------
    // Raw recording methods -- record exactly what happened
    // -------------------------------------------------------

    /**
     * Record a credit entry on this account.
     * This is a raw recording method -- it always creates a credit entry.
     * Use increase()/decrease() if you want automatic debit/credit selection.
     */
    public function credit(
        mixed $value,
        ?string $memo = null,
        ?Carbon $postDate = null,
        ?string $transactionGroup = null
    ): JournalEntry {
        $value = $value instanceof Money
            ? $value
            : new Money($value, new Currency($this->currency));

        return $this->post($value, null, $memo, $postDate, $transactionGroup);
    }

    /**
     * Record a debit entry on this account.
     * This is a raw recording method -- it always creates a debit entry.
     * Use increase()/decrease() if you want automatic debit/credit selection.
     */
    public function debit(
        mixed $value,
        ?string $memo = null,
        ?Carbon $postDate = null,
        ?string $transactionGroup = null
    ): JournalEntry {
        $value = $value instanceof Money
            ? $value
            : new Money($value, new Currency($this->currency));

        return $this->post(null, $value, $memo, $postDate, $transactionGroup);
    }

    public function creditDollars(
        float $value,
        ?string $memo = null,
        ?Carbon $postDate = null
    ): JournalEntry {
        return $this->credit((int) ($value * 100), $memo, $postDate);
    }

    public function debitDollars(
        float $value,
        ?string $memo = null,
        ?Carbon $postDate = null
    ): JournalEntry {
        return $this->debit((int) ($value * 100), $memo, $postDate);
    }

    // -------------------------------------------------------
    // Convenience methods -- auto-select debit or credit
    // -------------------------------------------------------

    /**
     * Increase this account's balance.
     * Automatically debits debit-normal accounts (Asset, Expense)
     * and credits credit-normal accounts (Liability, Equity, Income).
     */
    public function increase(
        mixed $value,
        ?string $memo = null,
        ?Carbon $postDate = null,
        ?string $transactionGroup = null
    ): JournalEntry {
        if ($this->isDebitNormal()) {
            return $this->debit($value, $memo, $postDate, $transactionGroup);
        }
        return $this->credit($value, $memo, $postDate, $transactionGroup);
    }

    /**
     * Decrease this account's balance.
     * Automatically credits debit-normal accounts (Asset, Expense)
     * and debits credit-normal accounts (Liability, Equity, Income).
     */
    public function decrease(
        mixed $value,
        ?string $memo = null,
        ?Carbon $postDate = null,
        ?string $transactionGroup = null
    ): JournalEntry {
        if ($this->isDebitNormal()) {
            return $this->credit($value, $memo, $postDate, $transactionGroup);
        }
        return $this->debit($value, $memo, $postDate, $transactionGroup);
    }

    /**
     * Increase balance using dollar amount.
     */
    public function increaseDollars(
        float $value,
        ?string $memo = null,
        ?Carbon $postDate = null
    ): JournalEntry {
        return $this->increase((int) ($value * 100), $memo, $postDate);
    }

    /**
     * Decrease balance using dollar amount.
     */
    public function decreaseDollars(
        float $value,
        ?string $memo = null,
        ?Carbon $postDate = null
    ): JournalEntry {
        return $this->decrease((int) ($value * 100), $memo, $postDate);
    }

    // -------------------------------------------------------
    // Daily totals
    // -------------------------------------------------------

    public function getDollarsDebitedToday(): float
    {
        return $this->getDollarsDebitedOn(Carbon::now());
    }

    public function getDollarsCreditedToday(): float
    {
        return $this->getDollarsCreditedOn(Carbon::now());
    }

    public function getDollarsDebitedOn(Carbon $date): float
    {
        return $this->journalEntries()
                ->whereBetween('post_date', [
                    $date->copy()->startOfDay(),
                    $date->copy()->endOfDay()
                ])
                ->sum('debit') / 100;
    }

    public function getDollarsCreditedOn(Carbon $date): float
    {
        return $this->journalEntries()
                ->whereBetween('post_date', [
                    $date->copy()->startOfDay(),
                    $date->copy()->endOfDay()
                ])
                ->sum('credit') / 100;
    }

    // -------------------------------------------------------
    // Private posting method
    // -------------------------------------------------------

    private function post(
        ?Money $credit = null,
        ?Money $debit = null,
        ?string $memo = null,
        ?Carbon $postDate = null,
        ?string $transactionGroup = null
    ): JournalEntry {
        $currencyCode = ($credit ?? $debit)->getCurrency()->getCode();

        $entry = $this->journalEntries()->create([
            'credit' => $credit?->getAmount(),
            'debit' => $debit?->getAmount(),
            'memo' => $memo,
            'currency' => $currencyCode,
            'post_date' => $postDate ?? Carbon::now(),
            'transaction_group' => $transactionGroup,
            'is_posted' => true,
        ]);

        $this->refresh();
        $this->balance = $this->getCurrentBalance();
        $this->save();

        return $entry;
    }
}
