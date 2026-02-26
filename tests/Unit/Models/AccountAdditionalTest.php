<?php

declare(strict_types=1);

namespace Tests\Unit\Models;

use Tests\Unit\TestCase;
use App\Accounting\Models\Account;
use App\Accounting\Models\AccountType;
use App\Accounting\Enums\AccountCategory;
use App\Accounting\Models\JournalEntry;
use Carbon\Carbon;
use Money\Money;
use Money\Currency;

class AccountAdditionalTest extends TestCase
{
    public function test_credit_with_raw_amount(): void
    {
        $account = Account::create([
            'currency' => 'USD',
            'morphed_type' => 'test',
            'morphed_id' => 1,
        ]);

        $entry = $account->credit(1500, 'Raw amount credit');

        $this->assertInstanceOf(JournalEntry::class, $entry);
        $this->assertEquals(1500, $entry->credit);
    }

    public function test_debit_with_raw_amount(): void
    {
        $account = Account::create([
            'currency' => 'USD',
            'morphed_type' => 'test',
            'morphed_id' => 1,
        ]);

        $entry = $account->debit(2000, 'Raw amount debit');

        $this->assertInstanceOf(JournalEntry::class, $entry);
        $this->assertEquals(2000, $entry->debit);
    }

    public function test_credit_with_transaction_group(): void
    {
        $account = Account::create([
            'currency' => 'USD',
            'morphed_type' => 'test',
            'morphed_id' => 1,
        ]);

        $money = new Money(1800, new Currency('USD'));
        $entry = $account->credit($money, 'Group credit', Carbon::now(), 'test-group-123');

        $this->assertEquals('test-group-123', $entry->transaction_group);
    }

    public function test_debit_with_transaction_group(): void
    {
        $account = Account::create([
            'currency' => 'USD',
            'morphed_type' => 'test',
            'morphed_id' => 1,
        ]);

        $money = new Money(2200, new Currency('USD'));
        $entry = $account->debit($money, 'Group debit', Carbon::now(), 'test-group-456');

        $this->assertEquals('test-group-456', $entry->transaction_group);
    }

    public function test_credit_dollars_with_post_date(): void
    {
        $account = Account::create([
            'currency' => 'USD',
            'morphed_type' => 'test',
            'morphed_id' => 1,
        ]);

        $postDate = Carbon::now()->subDays(5);
        $entry = $account->creditDollars(25.99, 'Credit with date', $postDate);

        $this->assertEquals(2599, $entry->credit);
        $this->assertEquals($postDate->format('Y-m-d H:i:s'), $entry->post_date->format('Y-m-d H:i:s'));
    }

    public function test_debit_dollars_with_post_date(): void
    {
        $account = Account::create([
            'currency' => 'USD',
            'morphed_type' => 'test',
            'morphed_id' => 1,
        ]);

        $postDate = Carbon::now()->subDays(3);
        $entry = $account->debitDollars(15.75, 'Debit with date', $postDate);

        $this->assertEquals(1575, $entry->debit);
        $this->assertEquals($postDate->format('Y-m-d H:i:s'), $entry->post_date->format('Y-m-d H:i:s'));
    }

    public function test_get_balance_with_no_entries(): void
    {
        $account = Account::create([
            'currency' => 'EUR',
            'morphed_type' => 'test',
            'morphed_id' => 1,
        ]);

        $balance = $account->getBalance();

        $this->assertEquals(0, $balance->getAmount());
        $this->assertEquals('EUR', $balance->getCurrency()->getCode());
    }

    public function test_increase_dollars_on_debit_normal_account(): void
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

        $entry = $account->increaseDollars(10.00, 'Dollar increase');

        $this->assertEquals(1000, $entry->debit); // debit for debit-normal account
        $this->assertEquals(0, $entry->credit);
    }

    public function test_increase_dollars_on_credit_normal_account(): void
    {
        $accountType = AccountType::create([
            'name' => 'Accounts Payable',
            'type' => AccountCategory::LIABILITY->value,
        ]);

        $account = Account::create([
            'account_type_id' => $accountType->id,
            'currency' => 'USD',
            'morphed_type' => 'test',
            'morphed_id' => 1,
        ]);

        $entry = $account->increaseDollars(10.00, 'Dollar increase liability');

        $this->assertEquals(1000, $entry->credit); // credit for credit-normal account
        $this->assertEquals(0, $entry->debit);
    }

    public function test_decrease_dollars_on_debit_normal_account(): void
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

        $account->increaseDollars(50.00, 'Initial');
        $entry = $account->decreaseDollars(20.00, 'Dollar decrease asset');

        $this->assertEquals(2000, $entry->credit); // credit for debit-normal account decrease
        $this->assertEquals(0, $entry->debit);
    }
}
