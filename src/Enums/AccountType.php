<?php

declare(strict_types=1);

namespace App\Accounting\Enums;

enum AccountType: string
{
    case ASSET = 'asset';
    case LIABILITY = 'liability';
    case EQUITY = 'equity';
    case INCOME = 'income';
    case EXPENSE = 'expense';

    /**
     * Get all enum string values.
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * Whether this account type has a normal debit balance.
     * Assets and Expenses increase with debits.
     */
    public function isDebitNormal(): bool
    {
        return match ($this) {
            self::ASSET, self::EXPENSE => true,
            default => false,
        };
    }

    /**
     * Whether this account type has a normal credit balance.
     * Liabilities, Equity, and Income increase with credits.
     */
    public function isCreditNormal(): bool
    {
        return !$this->isDebitNormal();
    }

    /**
     * Balance sign multiplier.
     * +1 for debit-normal (balance = debits - credits)
     * -1 for credit-normal (balance = credits - debits)
     */
    public function balanceSign(): int
    {
        return $this->isDebitNormal() ? 1 : -1;
    }

    /**
     * Human-readable label.
     */
    public function label(): string
    {
        return match ($this) {
            self::ASSET => 'Asset',
            self::LIABILITY => 'Liability',
            self::EQUITY => 'Equity',
            self::INCOME => 'Income',
            self::EXPENSE => 'Expense',
        };
    }
}
