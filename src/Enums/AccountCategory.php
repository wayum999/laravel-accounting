<?php

declare(strict_types=1);

namespace App\Accounting\Enums;

/**
 * The 5 fundamental account categories in double-entry accounting.
 * Follows the QuickBooks model.
 *
 * The accounting equation: Assets = Liabilities + Equity
 * Income and Expenses flow into Equity via Retained Earnings.
 */
enum AccountCategory: string
{
    /**
     * Resources owned by the company that provide future economic benefit.
     * Examples: Cash, Accounts Receivable, Inventory, Equipment.
     * Normal balance: Debit (increases with debits, decreases with credits).
     */
    case ASSET = 'asset';

    /**
     * Amounts owed by the company to external parties.
     * Examples: Accounts Payable, Loans Payable, Taxes Payable.
     * Normal balance: Credit (increases with credits, decreases with debits).
     */
    case LIABILITY = 'liability';

    /**
     * The owners' claim on the company's assets after all liabilities are deducted.
     * Examples: Common Stock, Retained Earnings, Owner's Capital.
     * Normal balance: Credit (increases with credits, decreases with debits).
     */
    case EQUITY = 'equity';

    /**
     * Income generated from the company's operations.
     * Examples: Sales Income, Service Income, Consulting Fees.
     * Normal balance: Credit (increases with credits, decreases with debits).
     */
    case INCOME = 'income';

    /**
     * Costs incurred in the process of generating income.
     * Examples: Salaries, Rent, Utilities, Cost of Goods Sold (COGS).
     * Normal balance: Debit (increases with debits, decreases with credits).
     */
    case EXPENSE = 'expense';

    /**
     * Gets all possible values.
     *
     * @return array<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * Determines if the account category has a normal debit balance.
     * Assets and Expenses increase with debits.
     */
    public function isDebitNormal(): bool
    {
        return in_array($this, [
            self::ASSET,
            self::EXPENSE,
        ]);
    }

    /**
     * Determines if the account category has a normal credit balance.
     * Liabilities, Equity, and Income increase with credits.
     */
    public function isCreditNormal(): bool
    {
        return in_array($this, [
            self::LIABILITY,
            self::EQUITY,
            self::INCOME,
        ]);
    }

    /**
     * Returns the balance sign multiplier.
     * +1 for debit-normal accounts (balance = debits - credits)
     * -1 for credit-normal accounts (balance = credits - debits)
     */
    public function balanceSign(): int
    {
        return $this->isDebitNormal() ? 1 : -1;
    }
}
