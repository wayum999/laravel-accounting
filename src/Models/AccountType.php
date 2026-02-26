<?php

declare(strict_types=1);

namespace App\Accounting\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Money\Money;
use Money\Currency;
use App\Accounting\Enums\AccountCategory;

class AccountType extends Model
{
    protected $table = 'accounting_account_types';

    protected $fillable = [
        'name',
        'type',
        'code',
        'parent_id',
        'description',
        'is_active',
    ];

    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'type' => AccountCategory::class,
        'is_active' => 'boolean',
    ];

    public function accounts(): HasMany
    {
        return $this->hasMany(Account::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    public function journalEntries(): HasManyThrough
    {
        return $this->hasManyThrough(JournalEntry::class, Account::class);
    }

    /**
     * Calculate the current balance for this account type across all accounts.
     * Respects the normal balance direction for the account category.
     */
    public function getCurrentBalance(string $currency): Money
    {
        // Use query builder (not collection) to always get fresh data from DB
        $debitTotal = $this->journalEntries()->sum('debit');
        $creditTotal = $this->journalEntries()->sum('credit');

        $balance = match ($this->type) {
            AccountCategory::ASSET,
            AccountCategory::EXPENSE =>
                $debitTotal - $creditTotal,
            default => // LIABILITY, EQUITY, INCOME
                $creditTotal - $debitTotal,
        };

        return new Money($balance, new Currency($currency));
    }

    public function getCurrentBalanceInDollars(): float
    {
        return $this->getCurrentBalance('USD')->getAmount() / 100;
    }

    /**
     * Returns label options for forms/dropdowns.
     */
    public static function getTypeOptions(): array
    {
        return array_combine(
            array_column(AccountCategory::cases(), 'value'),
            array_map(fn($case) => ucfirst($case->value), AccountCategory::cases())
        );
    }
}
