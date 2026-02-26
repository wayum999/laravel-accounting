<?php

declare(strict_types=1);

namespace Tests\Unit\Models;

use Tests\Unit\TestCase;
use App\Accounting\Models\AccountType;
use App\Accounting\Enums\AccountCategory;
use App\Accounting\Models\Account;
use App\Accounting\Models\JournalEntry;

class AccountTypeTest extends TestCase
{
    public function test_it_can_be_created_with_valid_attributes(): void
    {
        $accountType = AccountType::create([
            'name' => 'Test Account Type',
            'type' => AccountCategory::ASSET->value,
        ]);

        $this->assertInstanceOf(AccountType::class, $accountType);
        $this->assertEquals('Test Account Type', $accountType->name);
        $this->assertEquals(AccountCategory::ASSET, $accountType->type);
    }

    public function test_it_has_correct_type_options(): void
    {
        $expected = [
            'asset'     => 'Asset',
            'liability' => 'Liability',
            'equity'    => 'Equity',
            'income'    => 'Income',
            'expense'   => 'Expense',
        ];

        $this->assertEquals($expected, AccountType::getTypeOptions());
    }

    public function test_it_has_no_gain_loss_or_revenue_type_options(): void
    {
        $options = AccountType::getTypeOptions();

        $this->assertArrayNotHasKey('gain', $options);
        $this->assertArrayNotHasKey('loss', $options);
        $this->assertArrayNotHasKey('revenue', $options);
        $this->assertCount(5, $options);
    }

    public function test_it_calculates_balance_correctly_for_assets(): void
    {
        $accountType = AccountType::create([
            'name' => 'Asset Account Type',
            'type' => AccountCategory::ASSET->value,
        ]);

        $account = $accountType->accounts()->create([
            'currency' => 'USD',
            'morphed_type' => 'test',
            'morphed_id' => 1,
        ]);

        // Debit increases asset accounts
        $account->journalEntries()->create([
            'debit' => 1000,
            'credit' => 0,
            'currency' => 'USD',
            'memo' => 'Initial deposit',
            'post_date' => now(),
        ]);

        $balance = $accountType->getCurrentBalance('USD');
        $this->assertEquals(1000, $balance->getAmount());
    }

    public function test_it_calculates_balance_correctly_for_liabilities(): void
    {
        $accountType = AccountType::create([
            'name' => 'Liability Account Type',
            'type' => AccountCategory::LIABILITY->value,
        ]);

        $account = $accountType->accounts()->create([
            'currency' => 'USD',
            'morphed_type' => 'test',
            'morphed_id' => 1,
        ]);

        // Credit increases liability accounts
        $account->journalEntries()->create([
            'debit' => 0,
            'credit' => 1500,
            'currency' => 'USD',
            'memo' => 'Initial credit',
            'post_date' => now(),
        ]);

        $balance = $accountType->getCurrentBalance('USD');
        $this->assertEquals(1500, $balance->getAmount());
    }

    public function test_it_returns_correct_dollar_amount(): void
    {
        $accountType = AccountType::create([
            'name' => 'Test Account Type',
            'type' => AccountCategory::ASSET->value,
        ]);

        $account = $accountType->accounts()->create([
            'currency' => 'USD',
            'morphed_type' => 'test',
            'morphed_id' => 1,
        ]);

        $account->journalEntries()->create([
            'debit' => 1000, // $10.00
            'credit' => 0,
            'currency' => 'USD',
            'memo' => 'Test transaction',
            'post_date' => now(),
        ]);

        $this->assertEquals(10.0, $accountType->getCurrentBalanceInDollars());
    }

    public function test_it_has_accounts_relationship(): void
    {
        $accountType = AccountType::create([
            'name' => 'Test Account Type',
            'type' => AccountCategory::ASSET->value,
        ]);

        $account = $accountType->accounts()->create([
            'currency' => 'USD',
            'morphed_type' => 'test',
            'morphed_id' => 1,
        ]);

        $this->assertTrue($accountType->accounts->contains($account));
    }

    public function test_it_has_journal_entries_relationship(): void
    {
        $accountType = AccountType::create([
            'name' => 'Test Account Type',
            'type' => AccountCategory::ASSET->value,
        ]);

        $account = $accountType->accounts()->create([
            'currency' => 'USD',
            'morphed_type' => 'test',
            'morphed_id' => 1,
        ]);

        $entry = $account->journalEntries()->create([
            'debit' => 1000,
            'credit' => 0,
            'currency' => 'USD',
            'memo' => 'Test transaction',
            'post_date' => now(),
        ]);

        $this->assertTrue($accountType->journalEntries->contains($entry));
    }

    public function test_it_calculates_balance_correctly_for_equity(): void
    {
        $accountType = AccountType::create([
            'name' => 'Equity Account Type',
            'type' => AccountCategory::EQUITY->value,
        ]);

        $account = $accountType->accounts()->create([
            'currency' => 'USD',
            'morphed_type' => 'test',
            'morphed_id' => 1,
        ]);

        // Credit increases equity accounts
        $account->journalEntries()->create([
            'debit' => 0,
            'credit' => 2000,
            'currency' => 'USD',
            'memo' => 'Equity credit',
            'post_date' => now(),
        ]);

        $balance = $accountType->getCurrentBalance('USD');
        $this->assertEquals(2000, $balance->getAmount());
    }

    public function test_it_calculates_balance_correctly_for_income(): void
    {
        $accountType = AccountType::create([
            'name' => 'Income Account Type',
            'type' => AccountCategory::INCOME->value,
        ]);

        $account = $accountType->accounts()->create([
            'currency' => 'USD',
            'morphed_type' => 'test',
            'morphed_id' => 1,
        ]);

        // Credit increases income accounts
        $account->journalEntries()->create([
            'debit' => 0,
            'credit' => 3500,
            'currency' => 'USD',
            'memo' => 'Income credit',
            'post_date' => now(),
        ]);

        $balance = $accountType->getCurrentBalance('USD');
        $this->assertEquals(3500, $balance->getAmount());
    }

    public function test_it_calculates_balance_correctly_for_expense(): void
    {
        $accountType = AccountType::create([
            'name' => 'Expense Account Type',
            'type' => AccountCategory::EXPENSE->value,
        ]);

        $account = $accountType->accounts()->create([
            'currency' => 'USD',
            'morphed_type' => 'test',
            'morphed_id' => 1,
        ]);

        // Debit increases expense accounts
        $account->journalEntries()->create([
            'debit' => 2500,
            'credit' => 0,
            'currency' => 'USD',
            'memo' => 'Expense debit',
            'post_date' => now(),
        ]);

        $balance = $accountType->getCurrentBalance('USD');
        $this->assertEquals(2500, $balance->getAmount());
    }

    public function test_get_current_balance_with_no_accounts(): void
    {
        $accountType = AccountType::create([
            'name' => 'Empty Account Type',
            'type' => AccountCategory::ASSET->value,
        ]);

        $balance = $accountType->getCurrentBalance('USD');
        $this->assertEquals(0, $balance->getAmount());
    }

    public function test_get_current_balance_in_dollars_with_mixed_transactions(): void
    {
        $accountType = AccountType::create([
            'name' => 'Mixed Transaction Account Type',
            'type' => AccountCategory::ASSET->value,
        ]);

        $account = $accountType->accounts()->create([
            'currency' => 'USD',
            'morphed_type' => 'test',
            'morphed_id' => 1,
        ]);

        // Add multiple transactions
        $account->journalEntries()->createMany([
            [
                'debit' => 5000, // $50.00
                'credit' => 0,
                'currency' => 'USD',
                'memo' => 'Debit transaction',
                'post_date' => now(),
            ],
            [
                'debit' => 0,
                'credit' => 1500, // $15.00
                'currency' => 'USD',
                'memo' => 'Credit transaction',
                'post_date' => now(),
            ],
        ]);

        // For assets: debit - credit = 5000 - 1500 = 3500 = $35.00
        $balance = $accountType->getCurrentBalanceInDollars();
        $this->assertEquals(35.00, $balance);
    }
}
