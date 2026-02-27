<?php

declare(strict_types=1);

namespace App\Accounting\Services;

use App\Accounting\Enums\AccountSubType;
use App\Accounting\Enums\AccountType;
use App\Accounting\Models\Account;
use Illuminate\Support\Collection;

class ChartOfAccountsSeeder
{
    /**
     * Seed a minimal chart of accounts (7 essential accounts).
     */
    public static function seedMinimal(string $currency = 'USD'): Collection
    {
        return self::seedFromTemplate(self::minimalTemplate(), $currency);
    }

    /**
     * Seed a service business chart of accounts (consulting, agency, etc.).
     */
    public static function seedServiceBusiness(string $currency = 'USD'): Collection
    {
        return self::seedFromTemplate(self::serviceBusinessTemplate(), $currency);
    }

    /**
     * Seed a retail business chart of accounts (inventory, COGS, freight, etc.).
     */
    public static function seedRetailBusiness(string $currency = 'USD'): Collection
    {
        return self::seedFromTemplate(self::retailBusinessTemplate(), $currency);
    }

    /**
     * Seed accounts from a custom template array. Idempotent: skips if code already exists.
     *
     * Template format:
     * [
     *     ['code' => '1000', 'name' => 'Cash', 'type' => AccountType::ASSET, 'sub_type' => 'bank'],
     *     ...
     * ]
     */
    public static function seedFromTemplate(array $template, string $currency = 'USD'): Collection
    {
        $accounts = new Collection();

        foreach ($template as $item) {
            // Idempotent: skip if an account with this code already exists
            $existing = Account::where('code', $item['code'])->first();

            if ($existing) {
                $accounts->push($existing);
                continue;
            }

            $account = Account::create([
                'code' => $item['code'],
                'name' => $item['name'],
                'type' => $item['type'],
                'sub_type' => $item['sub_type'] ?? null,
                'description' => $item['description'] ?? null,
                'currency' => $currency,
                'is_active' => true,
            ]);

            $accounts->push($account);
        }

        return $accounts;
    }

    /**
     * Minimal template: 7 essential accounts for any business.
     */
    public static function minimalTemplate(): array
    {
        return [
            ['code' => '1000', 'name' => 'Cash', 'type' => AccountType::ASSET, 'sub_type' => AccountSubType::BANK, 'description' => 'Primary cash account'],
            ['code' => '1100', 'name' => 'Accounts Receivable', 'type' => AccountType::ASSET, 'sub_type' => AccountSubType::ACCOUNTS_RECEIVABLE, 'description' => 'Money owed by customers'],
            ['code' => '2000', 'name' => 'Accounts Payable', 'type' => AccountType::LIABILITY, 'sub_type' => AccountSubType::ACCOUNTS_PAYABLE, 'description' => 'Money owed to vendors'],
            ['code' => '3000', 'name' => "Owner's Equity", 'type' => AccountType::EQUITY, 'sub_type' => AccountSubType::OWNERS_EQUITY, 'description' => 'Owner investment in the business'],
            ['code' => '3100', 'name' => 'Retained Earnings', 'type' => AccountType::EQUITY, 'sub_type' => AccountSubType::RETAINED_EARNINGS, 'description' => 'Accumulated profits retained in the business'],
            ['code' => '4000', 'name' => 'Sales Revenue', 'type' => AccountType::INCOME, 'sub_type' => AccountSubType::REVENUE, 'description' => 'Income from sales'],
            ['code' => '5000', 'name' => 'General Expenses', 'type' => AccountType::EXPENSE, 'sub_type' => AccountSubType::OPERATING_EXPENSE, 'description' => 'General business expenses'],
        ];
    }

    /**
     * Service business template: consulting, agencies, professional services.
     */
    public static function serviceBusinessTemplate(): array
    {
        return [
            // Assets
            ['code' => '1000', 'name' => 'Cash', 'type' => AccountType::ASSET, 'sub_type' => AccountSubType::BANK],
            ['code' => '1010', 'name' => 'Checking Account', 'type' => AccountType::ASSET, 'sub_type' => AccountSubType::BANK],
            ['code' => '1020', 'name' => 'Savings Account', 'type' => AccountType::ASSET, 'sub_type' => AccountSubType::BANK],
            ['code' => '1100', 'name' => 'Accounts Receivable', 'type' => AccountType::ASSET, 'sub_type' => AccountSubType::ACCOUNTS_RECEIVABLE],
            ['code' => '1200', 'name' => 'Prepaid Expenses', 'type' => AccountType::ASSET, 'sub_type' => AccountSubType::OTHER_CURRENT_ASSET],
            ['code' => '1500', 'name' => 'Office Equipment', 'type' => AccountType::ASSET, 'sub_type' => AccountSubType::FIXED_ASSET],
            ['code' => '1510', 'name' => 'Computer Equipment', 'type' => AccountType::ASSET, 'sub_type' => AccountSubType::FIXED_ASSET],
            ['code' => '1600', 'name' => 'Accumulated Depreciation', 'type' => AccountType::ASSET, 'sub_type' => AccountSubType::FIXED_ASSET],

            // Liabilities
            ['code' => '2000', 'name' => 'Accounts Payable', 'type' => AccountType::LIABILITY, 'sub_type' => AccountSubType::ACCOUNTS_PAYABLE],
            ['code' => '2100', 'name' => 'Credit Card Payable', 'type' => AccountType::LIABILITY, 'sub_type' => AccountSubType::CREDIT_CARD],
            ['code' => '2200', 'name' => 'Accrued Liabilities', 'type' => AccountType::LIABILITY, 'sub_type' => AccountSubType::OTHER_CURRENT_LIABILITY],
            ['code' => '2300', 'name' => 'Payroll Liabilities', 'type' => AccountType::LIABILITY, 'sub_type' => AccountSubType::OTHER_CURRENT_LIABILITY],
            ['code' => '2400', 'name' => 'Sales Tax Payable', 'type' => AccountType::LIABILITY, 'sub_type' => AccountSubType::OTHER_CURRENT_LIABILITY],
            ['code' => '2500', 'name' => 'Unearned Revenue', 'type' => AccountType::LIABILITY, 'sub_type' => AccountSubType::OTHER_CURRENT_LIABILITY],

            // Equity
            ['code' => '3000', 'name' => "Owner's Equity", 'type' => AccountType::EQUITY, 'sub_type' => AccountSubType::OWNERS_EQUITY],
            ['code' => '3100', 'name' => 'Retained Earnings', 'type' => AccountType::EQUITY, 'sub_type' => AccountSubType::RETAINED_EARNINGS],
            ['code' => '3200', 'name' => "Owner's Draw", 'type' => AccountType::EQUITY, 'sub_type' => AccountSubType::OWNERS_EQUITY],

            // Income
            ['code' => '4000', 'name' => 'Service Revenue', 'type' => AccountType::INCOME, 'sub_type' => AccountSubType::REVENUE],
            ['code' => '4100', 'name' => 'Consulting Revenue', 'type' => AccountType::INCOME, 'sub_type' => AccountSubType::REVENUE],
            ['code' => '4200', 'name' => 'Project Revenue', 'type' => AccountType::INCOME, 'sub_type' => AccountSubType::REVENUE],
            ['code' => '4900', 'name' => 'Other Income', 'type' => AccountType::INCOME, 'sub_type' => AccountSubType::OTHER_INCOME],

            // Expenses
            ['code' => '5000', 'name' => 'Salaries & Wages', 'type' => AccountType::EXPENSE, 'sub_type' => AccountSubType::OPERATING_EXPENSE],
            ['code' => '5100', 'name' => 'Payroll Taxes', 'type' => AccountType::EXPENSE, 'sub_type' => AccountSubType::OPERATING_EXPENSE],
            ['code' => '5200', 'name' => 'Rent Expense', 'type' => AccountType::EXPENSE, 'sub_type' => AccountSubType::OPERATING_EXPENSE],
            ['code' => '5300', 'name' => 'Utilities Expense', 'type' => AccountType::EXPENSE, 'sub_type' => AccountSubType::OPERATING_EXPENSE],
            ['code' => '5400', 'name' => 'Office Supplies', 'type' => AccountType::EXPENSE, 'sub_type' => AccountSubType::OPERATING_EXPENSE],
            ['code' => '5500', 'name' => 'Software & Subscriptions', 'type' => AccountType::EXPENSE, 'sub_type' => AccountSubType::OPERATING_EXPENSE],
            ['code' => '5600', 'name' => 'Professional Development', 'type' => AccountType::EXPENSE, 'sub_type' => AccountSubType::OPERATING_EXPENSE],
            ['code' => '5700', 'name' => 'Travel Expense', 'type' => AccountType::EXPENSE, 'sub_type' => AccountSubType::OPERATING_EXPENSE],
            ['code' => '5800', 'name' => 'Marketing & Advertising', 'type' => AccountType::EXPENSE, 'sub_type' => AccountSubType::OPERATING_EXPENSE],
            ['code' => '5900', 'name' => 'Insurance Expense', 'type' => AccountType::EXPENSE, 'sub_type' => AccountSubType::OPERATING_EXPENSE],
            ['code' => '6000', 'name' => 'Depreciation Expense', 'type' => AccountType::EXPENSE, 'sub_type' => AccountSubType::OPERATING_EXPENSE],
            ['code' => '6100', 'name' => 'Bank Fees & Charges', 'type' => AccountType::EXPENSE, 'sub_type' => AccountSubType::OPERATING_EXPENSE],
            ['code' => '6200', 'name' => 'Interest Expense', 'type' => AccountType::EXPENSE, 'sub_type' => AccountSubType::OPERATING_EXPENSE],
            ['code' => '6900', 'name' => 'Miscellaneous Expense', 'type' => AccountType::EXPENSE, 'sub_type' => AccountSubType::OPERATING_EXPENSE],
        ];
    }

    /**
     * Retail business template: includes inventory, COGS, freight.
     */
    public static function retailBusinessTemplate(): array
    {
        return [
            // Assets
            ['code' => '1000', 'name' => 'Cash', 'type' => AccountType::ASSET, 'sub_type' => AccountSubType::BANK],
            ['code' => '1010', 'name' => 'Checking Account', 'type' => AccountType::ASSET, 'sub_type' => AccountSubType::BANK],
            ['code' => '1020', 'name' => 'Savings Account', 'type' => AccountType::ASSET, 'sub_type' => AccountSubType::BANK],
            ['code' => '1100', 'name' => 'Accounts Receivable', 'type' => AccountType::ASSET, 'sub_type' => AccountSubType::ACCOUNTS_RECEIVABLE],
            ['code' => '1200', 'name' => 'Inventory', 'type' => AccountType::ASSET, 'sub_type' => AccountSubType::INVENTORY],
            ['code' => '1300', 'name' => 'Prepaid Expenses', 'type' => AccountType::ASSET, 'sub_type' => AccountSubType::OTHER_CURRENT_ASSET],
            ['code' => '1500', 'name' => 'Store Equipment', 'type' => AccountType::ASSET, 'sub_type' => AccountSubType::FIXED_ASSET],
            ['code' => '1510', 'name' => 'Furniture & Fixtures', 'type' => AccountType::ASSET, 'sub_type' => AccountSubType::FIXED_ASSET],
            ['code' => '1520', 'name' => 'Leasehold Improvements', 'type' => AccountType::ASSET, 'sub_type' => AccountSubType::FIXED_ASSET],
            ['code' => '1600', 'name' => 'Accumulated Depreciation', 'type' => AccountType::ASSET, 'sub_type' => AccountSubType::FIXED_ASSET],

            // Liabilities
            ['code' => '2000', 'name' => 'Accounts Payable', 'type' => AccountType::LIABILITY, 'sub_type' => AccountSubType::ACCOUNTS_PAYABLE],
            ['code' => '2100', 'name' => 'Credit Card Payable', 'type' => AccountType::LIABILITY, 'sub_type' => AccountSubType::CREDIT_CARD],
            ['code' => '2200', 'name' => 'Accrued Liabilities', 'type' => AccountType::LIABILITY, 'sub_type' => AccountSubType::OTHER_CURRENT_LIABILITY],
            ['code' => '2300', 'name' => 'Payroll Liabilities', 'type' => AccountType::LIABILITY, 'sub_type' => AccountSubType::OTHER_CURRENT_LIABILITY],
            ['code' => '2400', 'name' => 'Sales Tax Payable', 'type' => AccountType::LIABILITY, 'sub_type' => AccountSubType::OTHER_CURRENT_LIABILITY],
            ['code' => '2500', 'name' => 'Short-Term Loan', 'type' => AccountType::LIABILITY, 'sub_type' => AccountSubType::OTHER_CURRENT_LIABILITY],
            ['code' => '2700', 'name' => 'Long-Term Loan', 'type' => AccountType::LIABILITY, 'sub_type' => AccountSubType::LONG_TERM_LIABILITY],

            // Equity
            ['code' => '3000', 'name' => "Owner's Equity", 'type' => AccountType::EQUITY, 'sub_type' => AccountSubType::OWNERS_EQUITY],
            ['code' => '3100', 'name' => 'Retained Earnings', 'type' => AccountType::EQUITY, 'sub_type' => AccountSubType::RETAINED_EARNINGS],
            ['code' => '3200', 'name' => "Owner's Draw", 'type' => AccountType::EQUITY, 'sub_type' => AccountSubType::OWNERS_EQUITY],

            // Income
            ['code' => '4000', 'name' => 'Sales Revenue', 'type' => AccountType::INCOME, 'sub_type' => AccountSubType::REVENUE],
            ['code' => '4100', 'name' => 'Returns & Allowances', 'type' => AccountType::INCOME, 'sub_type' => AccountSubType::REVENUE],
            ['code' => '4200', 'name' => 'Discounts Given', 'type' => AccountType::INCOME, 'sub_type' => AccountSubType::REVENUE],
            ['code' => '4300', 'name' => 'Shipping & Delivery Income', 'type' => AccountType::INCOME, 'sub_type' => AccountSubType::REVENUE],
            ['code' => '4900', 'name' => 'Other Income', 'type' => AccountType::INCOME, 'sub_type' => AccountSubType::OTHER_INCOME],

            // Cost of Goods Sold
            ['code' => '5000', 'name' => 'Cost of Goods Sold', 'type' => AccountType::EXPENSE, 'sub_type' => AccountSubType::COST_OF_GOODS_SOLD],
            ['code' => '5100', 'name' => 'Freight In', 'type' => AccountType::EXPENSE, 'sub_type' => AccountSubType::COST_OF_GOODS_SOLD],
            ['code' => '5200', 'name' => 'Purchase Discounts', 'type' => AccountType::EXPENSE, 'sub_type' => AccountSubType::COST_OF_GOODS_SOLD],
            ['code' => '5300', 'name' => 'Inventory Shrinkage', 'type' => AccountType::EXPENSE, 'sub_type' => AccountSubType::COST_OF_GOODS_SOLD],

            // Operating Expenses
            ['code' => '6000', 'name' => 'Salaries & Wages', 'type' => AccountType::EXPENSE, 'sub_type' => AccountSubType::OPERATING_EXPENSE],
            ['code' => '6100', 'name' => 'Payroll Taxes', 'type' => AccountType::EXPENSE, 'sub_type' => AccountSubType::OPERATING_EXPENSE],
            ['code' => '6200', 'name' => 'Rent Expense', 'type' => AccountType::EXPENSE, 'sub_type' => AccountSubType::OPERATING_EXPENSE],
            ['code' => '6300', 'name' => 'Utilities Expense', 'type' => AccountType::EXPENSE, 'sub_type' => AccountSubType::OPERATING_EXPENSE],
            ['code' => '6400', 'name' => 'Store Supplies', 'type' => AccountType::EXPENSE, 'sub_type' => AccountSubType::OPERATING_EXPENSE],
            ['code' => '6500', 'name' => 'Marketing & Advertising', 'type' => AccountType::EXPENSE, 'sub_type' => AccountSubType::OPERATING_EXPENSE],
            ['code' => '6600', 'name' => 'Insurance Expense', 'type' => AccountType::EXPENSE, 'sub_type' => AccountSubType::OPERATING_EXPENSE],
            ['code' => '6700', 'name' => 'Depreciation Expense', 'type' => AccountType::EXPENSE, 'sub_type' => AccountSubType::OPERATING_EXPENSE],
            ['code' => '6800', 'name' => 'Bank Fees & Charges', 'type' => AccountType::EXPENSE, 'sub_type' => AccountSubType::OPERATING_EXPENSE],
            ['code' => '6900', 'name' => 'Interest Expense', 'type' => AccountType::EXPENSE, 'sub_type' => AccountSubType::OPERATING_EXPENSE],
            ['code' => '7000', 'name' => 'Shipping & Delivery Expense', 'type' => AccountType::EXPENSE, 'sub_type' => AccountSubType::OPERATING_EXPENSE],
            ['code' => '7100', 'name' => 'Credit Card Processing Fees', 'type' => AccountType::EXPENSE, 'sub_type' => AccountSubType::OPERATING_EXPENSE],
            ['code' => '7900', 'name' => 'Miscellaneous Expense', 'type' => AccountType::EXPENSE, 'sub_type' => AccountSubType::OPERATING_EXPENSE],
        ];
    }
}
