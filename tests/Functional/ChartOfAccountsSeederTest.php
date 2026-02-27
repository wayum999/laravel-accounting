<?php

declare(strict_types=1);

namespace Tests\Functional;

use App\Accounting\Enums\AccountType;
use App\Accounting\Models\Account;
use App\Accounting\Services\ChartOfAccountsSeeder;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ChartOfAccountsSeederTest extends TestCase
{
    #[Test]
    public function it_seeds_minimal_accounts(): void
    {
        $accounts = ChartOfAccountsSeeder::seedMinimal();

        $this->assertCount(7, $accounts);
        $this->assertEquals(7, Account::count());

        // Verify specific accounts exist
        $this->assertNotNull(Account::where('code', '1000')->first()); // Cash
        $this->assertNotNull(Account::where('code', '1100')->first()); // AR
        $this->assertNotNull(Account::where('code', '2000')->first()); // AP
        $this->assertNotNull(Account::where('code', '3000')->first()); // Owner's Equity
        $this->assertNotNull(Account::where('code', '3100')->first()); // Retained Earnings
        $this->assertNotNull(Account::where('code', '4000')->first()); // Sales Revenue
        $this->assertNotNull(Account::where('code', '5000')->first()); // General Expenses
    }

    #[Test]
    public function it_is_idempotent(): void
    {
        ChartOfAccountsSeeder::seedMinimal();
        $this->assertEquals(7, Account::count());

        // Seed again — should not create duplicates
        ChartOfAccountsSeeder::seedMinimal();
        $this->assertEquals(7, Account::count());
    }

    #[Test]
    public function it_seeds_service_business_template(): void
    {
        $accounts = ChartOfAccountsSeeder::seedServiceBusiness();

        $this->assertGreaterThan(7, $accounts->count());

        // Verify service-specific accounts
        $this->assertNotNull(Account::where('name', 'Consulting Revenue')->first());
        $this->assertNotNull(Account::where('name', 'Software & Subscriptions')->first());
    }

    #[Test]
    public function it_seeds_retail_business_template(): void
    {
        $accounts = ChartOfAccountsSeeder::seedRetailBusiness();

        $this->assertGreaterThan(7, $accounts->count());

        // Verify retail-specific accounts
        $this->assertNotNull(Account::where('name', 'Inventory')->first());
        $this->assertNotNull(Account::where('name', 'Cost of Goods Sold')->first());
        $this->assertNotNull(Account::where('name', 'Freight In')->first());
    }

    #[Test]
    public function it_seeds_with_custom_currency(): void
    {
        $accounts = ChartOfAccountsSeeder::seedMinimal('EUR');

        foreach ($accounts as $account) {
            $this->assertEquals('EUR', $account->currency);
        }
    }

    #[Test]
    public function it_seeds_from_custom_template(): void
    {
        $template = [
            ['code' => '9000', 'name' => 'Custom Asset', 'type' => AccountType::ASSET],
            ['code' => '9100', 'name' => 'Custom Liability', 'type' => AccountType::LIABILITY],
        ];

        $accounts = ChartOfAccountsSeeder::seedFromTemplate($template);

        $this->assertCount(2, $accounts);
        $this->assertNotNull(Account::where('code', '9000')->first());
        $this->assertNotNull(Account::where('code', '9100')->first());
    }

    #[Test]
    public function minimal_template_has_correct_account_types(): void
    {
        ChartOfAccountsSeeder::seedMinimal();

        $cash = Account::where('code', '1000')->first();
        $this->assertEquals(AccountType::ASSET, $cash->type);

        $ar = Account::where('code', '1100')->first();
        $this->assertEquals(AccountType::ASSET, $ar->type);

        $ap = Account::where('code', '2000')->first();
        $this->assertEquals(AccountType::LIABILITY, $ap->type);

        $equity = Account::where('code', '3000')->first();
        $this->assertEquals(AccountType::EQUITY, $equity->type);

        $revenue = Account::where('code', '4000')->first();
        $this->assertEquals(AccountType::INCOME, $revenue->type);

        $expense = Account::where('code', '5000')->first();
        $this->assertEquals(AccountType::EXPENSE, $expense->type);
    }
}
