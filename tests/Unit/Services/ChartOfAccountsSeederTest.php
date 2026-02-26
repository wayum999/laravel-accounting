<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use Tests\TestCase;
use App\Accounting\Enums\AccountCategory;
use App\Accounting\Models\Account;
use App\Accounting\Models\AccountType;
use App\Accounting\Services\ChartOfAccountsSeeder;

class ChartOfAccountsSeederTest extends TestCase
{
    public function test_seed_minimal_creates_accounts(): void
    {
        $result = ChartOfAccountsSeeder::seedMinimal();

        $this->assertTrue($result->isNotEmpty());
        $this->assertNotNull(Account::where('number', '1000')->first(), 'Cash should exist');
        $this->assertNotNull(Account::where('number', '3100')->first(), 'Retained Earnings should exist');
    }

    public function test_seed_minimal_creates_correct_account_count(): void
    {
        ChartOfAccountsSeeder::seedMinimal();

        // 7 accounts: Cash, AR, AP, Owner's Equity, Retained Earnings, Sales Revenue, General Expenses
        $this->assertEquals(7, Account::count());
    }

    public function test_seed_minimal_creates_all_five_categories(): void
    {
        ChartOfAccountsSeeder::seedMinimal();

        $categories = AccountType::all()->pluck('type')->unique()->map->value->sort()->values();
        $expected = collect(AccountCategory::values())->sort()->values();

        $this->assertEquals($expected->toArray(), $categories->toArray());
    }

    public function test_seed_service_business_creates_accounts(): void
    {
        $result = ChartOfAccountsSeeder::seedServiceBusiness();

        $this->assertTrue($result->isNotEmpty());
        $this->assertNotNull(Account::where('number', '4100')->first(), 'Consulting Income should exist');
        $this->assertNotNull(Account::where('number', '6300')->first(), 'Rent Expense should exist');
    }

    public function test_seed_retail_business_includes_inventory_accounts(): void
    {
        ChartOfAccountsSeeder::seedRetailBusiness();

        $this->assertNotNull(Account::where('number', '1300')->first(), 'Inventory should exist');
        $this->assertNotNull(Account::where('number', '5000')->first(), 'COGS should exist');
        $this->assertNotNull(Account::where('number', '5300')->first(), 'Freight In should exist');
    }

    public function test_idempotent_seeding_no_duplicates(): void
    {
        ChartOfAccountsSeeder::seedMinimal();
        $firstCount = Account::count();

        ChartOfAccountsSeeder::seedMinimal();
        $secondCount = Account::count();

        $this->assertEquals($firstCount, $secondCount, 'Running seeder twice should not create duplicates');
    }

    public function test_seed_with_custom_currency(): void
    {
        ChartOfAccountsSeeder::seedMinimal('EUR');

        $cash = Account::where('number', '1000')->first();
        $this->assertEquals('EUR', $cash->currency);
    }

    public function test_seed_from_custom_template(): void
    {
        $template = [
            [
                'name' => 'Custom Assets',
                'category' => AccountCategory::ASSET,
                'code' => '1000',
                'accounts' => [
                    ['number' => '1000', 'name' => 'Custom Cash'],
                ],
            ],
        ];

        ChartOfAccountsSeeder::seedFromTemplate($template);

        $this->assertEquals(1, Account::count());
        $this->assertEquals('Custom Cash', Account::first()->name);
    }

    public function test_account_types_have_correct_categories(): void
    {
        ChartOfAccountsSeeder::seedMinimal();

        $cash = Account::where('number', '1000')->first();
        $this->assertEquals(AccountCategory::ASSET, $cash->accountType->type);

        $ap = Account::where('number', '2000')->first();
        $this->assertEquals(AccountCategory::LIABILITY, $ap->accountType->type);

        $equity = Account::where('number', '3000')->first();
        $this->assertEquals(AccountCategory::EQUITY, $equity->accountType->type);

        $revenue = Account::where('number', '4000')->first();
        $this->assertEquals(AccountCategory::INCOME, $revenue->accountType->type);

        $expense = Account::where('number', '6000')->first();
        $this->assertEquals(AccountCategory::EXPENSE, $expense->accountType->type);
    }

    public function test_minimal_template_returns_valid_array(): void
    {
        $template = ChartOfAccountsSeeder::minimalTemplate();

        $this->assertIsArray($template);
        $this->assertNotEmpty($template);

        foreach ($template as $typeDef) {
            $this->assertArrayHasKey('name', $typeDef);
            $this->assertArrayHasKey('category', $typeDef);
            $this->assertArrayHasKey('accounts', $typeDef);
            $this->assertInstanceOf(AccountCategory::class, $typeDef['category']);
        }
    }

    public function test_accounts_start_with_zero_balance(): void
    {
        ChartOfAccountsSeeder::seedMinimal();

        foreach (Account::all() as $account) {
            $this->assertEquals(0, $account->balance->getAmount());
        }
    }
}
