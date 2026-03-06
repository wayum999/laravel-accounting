<?php

declare(strict_types=1);

namespace App\Accounting\Enums;

enum AccountType: string
{
    case ASSET = 'asset';
    case LIABILITY = 'liability';
    case EQUITY = 'equity';
    case REVENUE = 'revenue';
    case EXPENSE = 'expense';
    case OTHER_INCOME = 'other_income';
    case OTHER_EXPENSE = 'other_expense';

    /**
     * Get all enum string values.
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * Whether this account type has a normal debit balance.
     * Assets, Expenses, and Other Expenses increase with debits.
     */
    public function isDebitNormal(): bool
    {
        return match ($this) {
            self::ASSET, self::EXPENSE, self::OTHER_EXPENSE => true,
            default => false,
        };
    }

    /**
     * Whether this account type has a normal credit balance.
     * Liabilities, Equity, Revenue, and Other Income increase with credits.
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
            self::REVENUE => 'Revenue',
            self::EXPENSE => 'Expense',
            self::OTHER_INCOME => 'Other Income',
            self::OTHER_EXPENSE => 'Other Expense',
        };
    }
}
