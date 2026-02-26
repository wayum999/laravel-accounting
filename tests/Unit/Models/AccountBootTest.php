<?php

declare(strict_types=1);

namespace Tests\Unit\Models;

use Tests\Unit\TestCase;
use App\Accounting\Models\Account;
use App\Accounting\Models\AccountType;
use App\Accounting\Enums\AccountCategory;

class AccountBootTest extends TestCase
{
    public function test_boot_creating_event_sets_zero_balance(): void
    {
        $account = new Account([
            'currency' => 'EUR',
            'morphed_type' => 'test',
            'morphed_id' => 1,
        ]);

        // Before save, balance attribute should not be set
        $this->assertNull($account->getAttributes()['balance'] ?? null);

        $account->save();

        // After save, the creating event should have set balance to 0
        $this->assertEquals(0, $account->getAttributes()['balance']);
    }

    public function test_boot_created_event_with_currency(): void
    {
        $account = Account::create([
            'currency' => 'USD',
            'morphed_type' => 'test',
            'morphed_id' => 1,
        ]);

        // The created event should have called resetCurrentBalances
        // Since there are no entries, balance should be 0
        $this->assertEquals(0, $account->balance->getAmount());
        $this->assertEquals('USD', $account->balance->getCurrency()->getCode());
    }

    public function test_boot_created_event_resets_balances_with_entries(): void
    {
        $accountType = AccountType::create([
            'name' => 'Cash',
            'type' => AccountCategory::ASSET->value,
        ]);

        $account = Account::create([
            'account_type_id' => $accountType->id,
            'currency' => 'USD',
            'morphed_type' => 'test',
            'morphed_id' => 1,
        ]);

        $account->journalEntries()->create([
            'debit' => 1500,
            'credit' => 0,
            'currency' => 'USD',
            'memo' => 'Test entry',
            'post_date' => now(),
        ]);

        $result = $account->resetCurrentBalances();

        // Asset (debit-normal): debit - credit = 1500 - 0 = 1500
        $this->assertEquals(1500, $result->getAmount());
    }

    public function test_balance_attribute_accessor_edge_cases(): void
    {
        $account = new Account([
            'currency' => 'GBP',
            'morphed_type' => 'test',
            'morphed_id' => 1,
        ]);

        $account->setRawAttributes(['balance' => 2500, 'currency' => 'GBP']);

        $balance = $account->balance;
        $this->assertEquals(2500, $balance->getAmount());
        $this->assertEquals('GBP', $balance->getCurrency()->getCode());
    }

    public function test_balance_attribute_mutator_edge_cases(): void
    {
        $account = new Account([
            'morphed_type' => 'test',
            'morphed_id' => 1,
        ]);

        // Test with non-numeric string
        $account->balance = 'invalid';
        $this->assertEquals(0, $account->getAttributes()['balance']);
        $this->assertEquals('USD', $account->currency); // Should default to USD

        // Test with null
        $account->currency = 'EUR';
        $account->balance = null;
        $this->assertEquals(0, $account->getAttributes()['balance']);
    }
}
