<?php

declare(strict_types=1);

namespace App\Accounting\Services;

use App\Accounting\Enums\AccountCategory;
use App\Accounting\Models\Account;
use App\Accounting\Models\AccountType;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ChartOfAccountsSeeder
{
    public static function seedMinimal(string $currency = 'USD'): Collection
    {
        return self::seedFromTemplate(self::minimalTemplate(), $currency);
    }

    public static function seedServiceBusiness(string $currency = 'USD'): Collection
    {
        return self::seedFromTemplate(self::serviceBusinessTemplate(), $currency);
    }

    public static function seedRetailBusiness(string $currency = 'USD'): Collection
    {
        return self::seedFromTemplate(self::retailBusinessTemplate(), $currency);
    }

    public static function seedFromTemplate(array $template, string $currency = 'USD'): Collection
    {
        $created = collect();

        DB::transaction(function () use ($template, $currency, &$created) {
            foreach ($template as $typeDefinition) {
                $accountType = AccountType::firstOrCreate(
                    ['name' => $typeDefinition['name']],
                    [
                        'type' => $typeDefinition['category'],
                        'code' => $typeDefinition['code'] ?? null,
                    ]
                );

                $created->push($accountType);

                foreach ($typeDefinition['accounts'] ?? [] as $accountDef) {
                    $existing = Account::where('number', $accountDef['number'])->first();

                    if (!$existing) {
                        $account = Account::create([
                            'name' => $accountDef['name'],
                            'number' => $accountDef['number'],
                            'account_type_id' => $accountType->id,
                            'currency' => $currency,
                            'morphed_type' => 'system',
                            'morphed_id' => 0,
                        ]);
                        $created->push($account);
                    }
                }
            }
        });

        return $created;
    }

    public static function minimalTemplate(): array
    {
        return [
            [
                'name' => 'Current Assets',
                'category' => AccountCategory::ASSET,
                'code' => '1000',
                'accounts' => [
                    ['number' => '1000', 'name' => 'Cash'],
                    ['number' => '1100', 'name' => 'Accounts Receivable'],
                ],
            ],
            [
                'name' => 'Current Liabilities',
                'category' => AccountCategory::LIABILITY,
                'code' => '2000',
                'accounts' => [
                    ['number' => '2000', 'name' => 'Accounts Payable'],
                ],
            ],
            [
                'name' => 'Equity',
                'category' => AccountCategory::EQUITY,
                'code' => '3000',
                'accounts' => [
                    ['number' => '3000', 'name' => "Owner's Equity"],
                    ['number' => '3100', 'name' => 'Retained Earnings'],
                ],
            ],
            [
                'name' => 'Revenue',
                'category' => AccountCategory::INCOME,
                'code' => '4000',
                'accounts' => [
                    ['number' => '4000', 'name' => 'Sales Revenue'],
                ],
            ],
            [
                'name' => 'Operating Expenses',
                'category' => AccountCategory::EXPENSE,
                'code' => '6000',
                'accounts' => [
                    ['number' => '6000', 'name' => 'General Expenses'],
                ],
            ],
        ];
    }

    public static function serviceBusinessTemplate(): array
    {
        return [
            [
                'name' => 'Current Assets',
                'category' => AccountCategory::ASSET,
                'code' => '1000',
                'accounts' => [
                    ['number' => '1000', 'name' => 'Cash'],
                    ['number' => '1010', 'name' => 'Petty Cash'],
                    ['number' => '1020', 'name' => 'Checking Account'],
                    ['number' => '1030', 'name' => 'Savings Account'],
                    ['number' => '1100', 'name' => 'Accounts Receivable'],
                    ['number' => '1200', 'name' => 'Prepaid Expenses'],
                    ['number' => '1210', 'name' => 'Prepaid Insurance'],
                ],
            ],
            [
                'name' => 'Fixed Assets',
                'category' => AccountCategory::ASSET,
                'code' => '1500',
                'accounts' => [
                    ['number' => '1500', 'name' => 'Office Equipment'],
                    ['number' => '1510', 'name' => 'Accumulated Depreciation - Equipment'],
                    ['number' => '1600', 'name' => 'Vehicles'],
                    ['number' => '1610', 'name' => 'Accumulated Depreciation - Vehicles'],
                ],
            ],
            [
                'name' => 'Current Liabilities',
                'category' => AccountCategory::LIABILITY,
                'code' => '2000',
                'accounts' => [
                    ['number' => '2000', 'name' => 'Accounts Payable'],
                    ['number' => '2100', 'name' => 'Credit Card Payable'],
                    ['number' => '2200', 'name' => 'Wages Payable'],
                    ['number' => '2300', 'name' => 'Taxes Payable'],
                    ['number' => '2310', 'name' => 'Sales Tax Payable'],
                    ['number' => '2400', 'name' => 'Unearned Revenue'],
                ],
            ],
            [
                'name' => 'Long-Term Liabilities',
                'category' => AccountCategory::LIABILITY,
                'code' => '2500',
                'accounts' => [
                    ['number' => '2500', 'name' => 'Notes Payable'],
                    ['number' => '2600', 'name' => 'Loan Payable'],
                ],
            ],
            [
                'name' => 'Equity',
                'category' => AccountCategory::EQUITY,
                'code' => '3000',
                'accounts' => [
                    ['number' => '3000', 'name' => "Owner's Equity"],
                    ['number' => '3100', 'name' => 'Retained Earnings'],
                    ['number' => '3200', 'name' => "Owner's Draws"],
                ],
            ],
            [
                'name' => 'Service Revenue',
                'category' => AccountCategory::INCOME,
                'code' => '4000',
                'accounts' => [
                    ['number' => '4000', 'name' => 'Service Revenue'],
                    ['number' => '4100', 'name' => 'Consulting Income'],
                    ['number' => '4200', 'name' => 'Other Income'],
                    ['number' => '4300', 'name' => 'Interest Income'],
                ],
            ],
            [
                'name' => 'Operating Expenses',
                'category' => AccountCategory::EXPENSE,
                'code' => '6000',
                'accounts' => [
                    ['number' => '6000', 'name' => 'Advertising Expense'],
                    ['number' => '6100', 'name' => 'Insurance Expense'],
                    ['number' => '6200', 'name' => 'Office Supplies Expense'],
                    ['number' => '6300', 'name' => 'Rent Expense'],
                    ['number' => '6400', 'name' => 'Salaries Expense'],
                    ['number' => '6500', 'name' => 'Telephone Expense'],
                    ['number' => '6600', 'name' => 'Utilities Expense'],
                    ['number' => '6700', 'name' => 'Depreciation Expense'],
                    ['number' => '6800', 'name' => 'Professional Fees'],
                    ['number' => '6900', 'name' => 'Miscellaneous Expense'],
                ],
            ],
        ];
    }

    public static function retailBusinessTemplate(): array
    {
        $service = self::serviceBusinessTemplate();

        // Add Inventory to Current Assets
        $service[0]['accounts'][] = ['number' => '1300', 'name' => 'Inventory'];
        $service[0]['accounts'][] = ['number' => '1310', 'name' => 'Inventory in Transit'];

        // Add COGS section
        $service[] = [
            'name' => 'Cost of Goods Sold',
            'category' => AccountCategory::EXPENSE,
            'code' => '5000',
            'accounts' => [
                ['number' => '5000', 'name' => 'Cost of Goods Sold'],
                ['number' => '5100', 'name' => 'Purchase Discounts'],
                ['number' => '5200', 'name' => 'Purchase Returns'],
                ['number' => '5300', 'name' => 'Freight In'],
                ['number' => '5400', 'name' => 'Inventory Shrinkage'],
            ],
        ];

        // Add Sales-specific revenue
        $service[5]['accounts'][] = ['number' => '4400', 'name' => 'Sales Returns & Allowances'];
        $service[5]['accounts'][] = ['number' => '4500', 'name' => 'Sales Discounts'];

        return $service;
    }
}
