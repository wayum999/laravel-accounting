<?php

declare(strict_types=1);

namespace App\Accounting\Enums;

enum AccountSubType: string
{
    // Asset sub-types
    case BANK = 'bank';
    case ACCOUNTS_RECEIVABLE = 'accounts_receivable';
    case OTHER_CURRENT_ASSET = 'other_current_asset';
    case INVENTORY = 'inventory';
    case FIXED_ASSET = 'fixed_asset';
    case OTHER_ASSET = 'other_asset';

    // Liability sub-types
    case ACCOUNTS_PAYABLE = 'accounts_payable';
    case CREDIT_CARD = 'credit_card';
    case OTHER_CURRENT_LIABILITY = 'other_current_liability';
    case LONG_TERM_LIABILITY = 'long_term_liability';

    // Equity sub-types
    case OWNERS_EQUITY = 'owners_equity';
    case RETAINED_EARNINGS = 'retained_earnings';

    // Revenue sub-types (including contra-revenue)
    case REVENUE = 'revenue';
    case SALES_DISCOUNTS = 'sales_discounts';
    case SALES_RETURNS_ALLOWANCES = 'sales_returns_allowances';

    // Other Income sub-types (non-operating income)
    case OTHER_INCOME = 'other_income';
    case GAIN_ON_SALE = 'gain_on_sale';
    case OTHER_GAIN = 'other_gain';

    // Expense sub-types
    case COST_OF_GOODS_SOLD = 'cost_of_goods_sold';
    case OPERATING_EXPENSE = 'operating_expense';

    // Other Expense sub-types (non-operating expenses)
    case OTHER_EXPENSE = 'other_expense';
    case LOSS_ON_SALE = 'loss_on_sale';
    case OTHER_LOSS = 'other_loss';

    /**
     * Which parent AccountType this sub-type belongs to.
     */
    public function parentType(): AccountType
    {
        return match ($this) {
            self::BANK,
            self::ACCOUNTS_RECEIVABLE,
            self::OTHER_CURRENT_ASSET,
            self::INVENTORY,
            self::FIXED_ASSET,
            self::OTHER_ASSET => AccountType::ASSET,

            self::ACCOUNTS_PAYABLE,
            self::CREDIT_CARD,
            self::OTHER_CURRENT_LIABILITY,
            self::LONG_TERM_LIABILITY => AccountType::LIABILITY,

            self::OWNERS_EQUITY,
            self::RETAINED_EARNINGS => AccountType::EQUITY,

            self::REVENUE,
            self::SALES_DISCOUNTS,
            self::SALES_RETURNS_ALLOWANCES => AccountType::REVENUE,

            self::OTHER_INCOME,
            self::GAIN_ON_SALE,
            self::OTHER_GAIN => AccountType::OTHER_INCOME,

            self::COST_OF_GOODS_SOLD,
            self::OPERATING_EXPENSE => AccountType::EXPENSE,

            self::OTHER_EXPENSE,
            self::LOSS_ON_SALE,
            self::OTHER_LOSS => AccountType::OTHER_EXPENSE,
        };
    }

    /**
     * The report section group label for display in financial statements.
     */
    public function reportGroup(): string
    {
        return match ($this) {
            self::BANK,
            self::ACCOUNTS_RECEIVABLE,
            self::OTHER_CURRENT_ASSET,
            self::INVENTORY => 'Current Assets',

            self::FIXED_ASSET => 'Fixed Assets',
            self::OTHER_ASSET => 'Other Assets',

            self::ACCOUNTS_PAYABLE,
            self::CREDIT_CARD,
            self::OTHER_CURRENT_LIABILITY => 'Current Liabilities',

            self::LONG_TERM_LIABILITY => 'Long-Term Liabilities',

            self::OWNERS_EQUITY,
            self::RETAINED_EARNINGS => 'Equity',

            self::REVENUE,
            self::SALES_DISCOUNTS,
            self::SALES_RETURNS_ALLOWANCES => 'Revenue',

            self::OTHER_INCOME,
            self::GAIN_ON_SALE,
            self::OTHER_GAIN => 'Other Income',

            self::COST_OF_GOODS_SOLD => 'Cost of Goods Sold',
            self::OPERATING_EXPENSE => 'Operating Expenses',

            self::OTHER_EXPENSE,
            self::LOSS_ON_SALE,
            self::OTHER_LOSS => 'Other Expenses',
        };
    }

    /**
     * Whether this sub-type represents a current (short-term) item.
     */
    public function isCurrent(): bool
    {
        return match ($this) {
            self::BANK,
            self::ACCOUNTS_RECEIVABLE,
            self::OTHER_CURRENT_ASSET,
            self::INVENTORY,
            self::ACCOUNTS_PAYABLE,
            self::CREDIT_CARD,
            self::OTHER_CURRENT_LIABILITY => true,

            default => false,
        };
    }

    /**
     * Human-readable label.
     */
    public function label(): string
    {
        return match ($this) {
            self::BANK => 'Bank',
            self::ACCOUNTS_RECEIVABLE => 'Accounts Receivable',
            self::OTHER_CURRENT_ASSET => 'Other Current Asset',
            self::INVENTORY => 'Inventory',
            self::FIXED_ASSET => 'Fixed Asset',
            self::OTHER_ASSET => 'Other Asset',
            self::ACCOUNTS_PAYABLE => 'Accounts Payable',
            self::CREDIT_CARD => 'Credit Card',
            self::OTHER_CURRENT_LIABILITY => 'Other Current Liability',
            self::LONG_TERM_LIABILITY => 'Long-Term Liability',
            self::OWNERS_EQUITY => "Owner's Equity",
            self::RETAINED_EARNINGS => 'Retained Earnings',
            self::REVENUE => 'Revenue',
            self::SALES_DISCOUNTS => 'Sales Discounts',
            self::SALES_RETURNS_ALLOWANCES => 'Sales Returns & Allowances',
            self::OTHER_INCOME => 'Other Income',
            self::GAIN_ON_SALE => 'Gain on Sale',
            self::OTHER_GAIN => 'Other Gain',
            self::COST_OF_GOODS_SOLD => 'Cost of Goods Sold',
            self::OPERATING_EXPENSE => 'Operating Expense',
            self::OTHER_EXPENSE => 'Other Expense',
            self::LOSS_ON_SALE => 'Loss on Sale',
            self::OTHER_LOSS => 'Other Loss',
        };
    }

    /**
     * Get all sub-types for a given parent AccountType.
     */
    public static function forType(AccountType $type): array
    {
        return array_values(array_filter(
            self::cases(),
            fn (self $subType) => $subType->parentType() === $type
        ));
    }
}
