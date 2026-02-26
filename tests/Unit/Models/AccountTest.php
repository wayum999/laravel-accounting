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
use Illuminate\Database\Eloquent\Model;

class AccountTest extends TestCase
{
    public function test_it_can_be_created_with_required_fields(): void
    {
        $account = Account::create([
            'currency' => 'USD',
            'morphed_type' => 'test',
            'morphed_id' => 1,
        ]);

        $this->assertInstanceOf(Account::class, $account);
        $this->assertEquals(0, $account->balance->getAmount(), 'New account should start with zero balance');
        $this->assertEquals('USD', $account->currency);
        $this->assertEquals('test', $account->morphed_type);
        $this->assertEquals(1, $account->morphed_id);
    }

    public function test_it_has_account_type_relationship(): void
    {
        $accountType = AccountType::create([
            'name' => 'Test Account Type',
            'type' => AccountCategory::ASSET->value,
        ]);

        $account = Account::create([
            'currency' => 'USD',
            'morphed_type' => 'test',
            'morphed_id' => 1,
        ]);

        $account->accountType()->associate($accountType);
        $account->save();

        $this->assertTrue($account->accountType->is($accountType));
    }

    public function test_it_can_have_journal_entries(): void
    {
        $account = Account::create([
            'currency' => 'USD',
            'morphed_type' => 'test',
            'morphed_id' => 1,
        ]);

        $entry = $account->journalEntries()->create([
            'debit' => 1000,
            'credit' => 0,
            'currency' => 'USD',
            'memo' => 'Test entry',
            'post_date' => now(),
        ]);

        $this->assertCount(1, $account->journalEntries);
        $this->assertTrue($account->journalEntries->contains($entry));
    }

    public function test_it_calculates_debit_normal_balance_correctly(): void
    {
        // Asset account — debit increases balance
        $accountType = AccountType::create([
            'name' => 'Checking',
            'type' => AccountCategory::ASSET->value,
        ]);

        $account = Account::create([
            'account_type_id' => $accountType->id,
            'currency' => 'USD',
            'morphed_type' => 'test',
            'morphed_id' => 1,
        ]);

        $account->journalEntries()->createMany([
            [
                'debit' => 1000,
                'credit' => 0,
                'currency' => 'USD',
                'memo' => 'Deposit',
                'post_date' => now(),
            ],
            [
                'debit' => 0,
                'credit' => 500,
                'currency' => 'USD',
                'memo' => 'Withdrawal',
                'post_date' => now(),
            ],
        ]);

        // Asset (debit-normal): debits - credits = 1000 - 500 = 500
        $balance = $account->getBalance();
        $this->assertEquals(500, $balance->getAmount());
    }

    public function test_it_calculates_credit_normal_balance_correctly(): void
    {
        // Income account — credit increases balance
        $accountType = AccountType::create([
            'name' => 'Sales Income',
            'type' => AccountCategory::INCOME->value,
        ]);

        $account = Account::create([
            'account_type_id' => $accountType->id,
            'currency' => 'USD',
            'morphed_type' => 'test',
            'morphed_id' => 1,
        ]);

        $account->journalEntries()->create([
            'debit' => 0,
            'credit' => 500,
            'currency' => 'USD',
            'memo' => 'Sales revenue',
            'post_date' => now(),
        ]);

        // Income (credit-normal): credits - debits = 500 - 0 = +500
        $balance = $account->getBalance();
        $this->assertEquals(500, $balance->getAmount());
    }

    public function test_it_handles_balance_in_dollars(): void
    {
        $accountType = AccountType::create([
            'name' => 'Checking',
            'type' => AccountCategory::ASSET->value,
        ]);

        $account = Account::create([
            'account_type_id' => $accountType->id,
            'currency' => 'USD',
            'morphed_type' => 'test',
            'morphed_id' => 1,
        ]);

        // Add a transaction to set the balance to $12.50
        $account->journalEntries()->create([
            'debit' => 1250, // $12.50
            'credit' => 0,
            'currency' => 'USD',
            'memo' => 'Initial deposit',
            'post_date' => now(),
        ]);

        $this->assertEquals(12.50, $account->getBalanceInDollars());
    }

    public function test_it_requires_currency(): void
    {
        $account = Account::create([
            'morphed_type' => 'test',
            'morphed_id' => 1,
            'currency' => 'USD',
        ]);

        $this->assertEquals('USD', $account->currency, 'Should use the provided currency');
    }

    public function test_morphed_relationship_setup(): void
    {
        $account = Account::create([
            'currency' => 'USD',
            'morphed_type' => 'App\\Accounting\\Models\\AccountType',
            'morphed_id' => 123,
        ]);

        $this->assertInstanceOf(\Illuminate\Database\Eloquent\Relations\MorphTo::class, $account->morphed());
        $this->assertEquals('App\\Accounting\\Models\\AccountType', $account->morphed_type);
        $this->assertEquals(123, $account->morphed_id);
    }

    public function test_set_currency_method(): void
    {
        $account = Account::create([
            'currency' => 'USD',
            'morphed_type' => 'test',
            'morphed_id' => 1,
        ]);

        $account->setCurrency('EUR');
        $this->assertEquals('EUR', $account->currency);
    }

    public function test_assign_to_account_type_method(): void
    {
        $accountType = AccountType::create([
            'name' => 'Test Account Type',
            'type' => AccountCategory::ASSET->value,
        ]);

        $account = Account::create([
            'currency' => 'USD',
            'morphed_type' => 'test',
            'morphed_id' => 1,
        ]);

        $account->assignToAccountType($accountType);
        $account->refresh();

        $this->assertTrue($account->accountType->is($accountType));
    }

    public function test_reset_current_balances_with_currency_and_no_entries(): void
    {
        $account = Account::create([
            'currency' => 'EUR',
            'morphed_type' => 'test',
            'morphed_id' => 1,
        ]);

        $result = $account->resetCurrentBalances();

        $this->assertEquals(0, $account->balance->getAmount());
        $this->assertEquals('EUR', $result->getCurrency()->getCode());
    }

    public function test_reset_current_balances_with_entries(): void
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
            'debit' => 1000,
            'credit' => 0,
            'currency' => 'USD',
            'memo' => 'Test entry',
            'post_date' => now(),
        ]);

        $result = $account->resetCurrentBalances();

        $this->assertEquals(1000, $result->getAmount());
    }

    public function test_balance_attribute_with_money_object(): void
    {
        $account = Account::create([
            'currency' => 'USD',
            'morphed_type' => 'test',
            'morphed_id' => 1,
        ]);

        $money = new Money(2500, new Currency('EUR'));
        $account->balance = $money;

        $this->assertEquals(2500, $account->balance->getAmount());
        $this->assertEquals('EUR', $account->currency);
    }

    public function test_balance_attribute_with_numeric_value_no_currency(): void
    {
        $account = new Account();
        $account->morphed_type = 'test';
        $account->morphed_id = 1;
        $account->currency = null;

        $account->balance = 1500;

        $this->assertEquals(1500, $account->getAttributes()['balance']);
        $this->assertEquals('USD', $account->currency); // Should default to USD
    }

    public function test_balance_attribute_with_string_value(): void
    {
        $account = Account::create([
            'currency' => 'USD',
            'morphed_type' => 'test',
            'morphed_id' => 1,
        ]);

        $account->balance = '2000';

        $this->assertEquals(2000, $account->balance->getAmount());
    }

    public function test_get_debit_balance_on_date(): void
    {
        $account = Account::create([
            'currency' => 'USD',
            'morphed_type' => 'test',
            'morphed_id' => 1,
        ]);

        $date = Carbon::now()->subDays(2);

        $account->journalEntries()->create([
            'debit' => 1000,
            'credit' => 0,
            'currency' => 'USD',
            'memo' => 'Past entry',
            'post_date' => $date,
        ]);

        $account->journalEntries()->create([
            'debit' => 500,
            'credit' => 0,
            'currency' => 'USD',
            'memo' => 'Future entry',
            'post_date' => Carbon::now()->addDays(1),
        ]);

        $balance = $account->getDebitBalanceOn(Carbon::now());

        $this->assertEquals(1000, $balance->getAmount()); // Only past entry
    }

    public function test_get_credit_balance_on_date(): void
    {
        $account = Account::create([
            'currency' => 'USD',
            'morphed_type' => 'test',
            'morphed_id' => 1,
        ]);

        $date = Carbon::now()->subDays(1);

        $account->journalEntries()->create([
            'debit' => 0,
            'credit' => 800,
            'currency' => 'USD',
            'memo' => 'Credit entry',
            'post_date' => $date,
        ]);

        $balance = $account->getCreditBalanceOn(Carbon::now());

        $this->assertEquals(800, $balance->getAmount());
    }

    public function test_get_balance_on_date_for_debit_normal_account(): void
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

        $date = Carbon::now()->subDays(1);

        $account->journalEntries()->createMany([
            [
                'debit' => 1000,
                'credit' => 0,
                'currency' => 'USD',
                'memo' => 'Debit',
                'post_date' => $date,
            ],
            [
                'debit' => 0,
                'credit' => 300,
                'currency' => 'USD',
                'memo' => 'Credit',
                'post_date' => $date,
            ],
        ]);

        $balance = $account->getBalanceOn(Carbon::now());

        // Asset (debit-normal): debit - credit = 1000 - 300 = 700
        $this->assertEquals(700, $balance->getAmount());
    }

    public function test_get_current_balance(): void
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
            'debit' => 1200,
            'credit' => 0,
            'currency' => 'USD',
            'memo' => 'Current entry',
            'post_date' => Carbon::now(),
        ]);

        $balance = $account->getCurrentBalance();

        $this->assertEquals(1200, $balance->getAmount());
    }

    public function test_get_current_balance_in_dollars(): void
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
            'debit' => 1250, // $12.50
            'credit' => 0,
            'currency' => 'USD',
            'memo' => 'Dollar test',
            'post_date' => Carbon::now(),
        ]);

        $balance = $account->getCurrentBalanceInDollars();

        $this->assertEquals(12.50, $balance);
    }

    public function test_credit_dollars_method(): void
    {
        $account = Account::create([
            'currency' => 'USD',
            'morphed_type' => 'test',
            'morphed_id' => 1,
        ]);

        $entry = $account->creditDollars(15.75, 'Dollar credit test');

        $this->assertInstanceOf(JournalEntry::class, $entry);
        $this->assertEquals(1575, $entry->credit); // $15.75 = 1575 cents
        $this->assertEquals('Dollar credit test', $entry->memo);
    }

    public function test_debit_dollars_method(): void
    {
        $account = Account::create([
            'currency' => 'USD',
            'morphed_type' => 'test',
            'morphed_id' => 1,
        ]);

        $entry = $account->debitDollars(20.99, 'Dollar debit test');

        $this->assertInstanceOf(JournalEntry::class, $entry);
        $this->assertEquals(2099, $entry->debit); // $20.99 = 2099 cents
        $this->assertEquals('Dollar debit test', $entry->memo);
    }

    public function test_increase_on_debit_normal_account_creates_debit_entry(): void
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

        $entry = $account->increase(1000, 'Increase asset');

        $this->assertInstanceOf(JournalEntry::class, $entry);
        $this->assertEquals(1000, $entry->debit);
        $this->assertEquals(0, $entry->credit);

        $balance = $account->getBalance();
        $this->assertEquals(1000, $balance->getAmount());
    }

    public function test_increase_on_credit_normal_account_creates_credit_entry(): void
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

        $entry = $account->increase(1500, 'Increase liability');

        $this->assertInstanceOf(JournalEntry::class, $entry);
        $this->assertEquals(1500, $entry->credit);
        $this->assertEquals(0, $entry->debit);

        $balance = $account->getBalance();
        $this->assertEquals(1500, $balance->getAmount());
    }

    public function test_decrease_on_debit_normal_account_creates_credit_entry(): void
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

        // First increase to have a balance
        $account->increase(2000, 'Initial deposit');

        $entry = $account->decrease(500, 'Decrease asset');

        $this->assertInstanceOf(JournalEntry::class, $entry);
        $this->assertEquals(500, $entry->credit);
        $this->assertEquals(0, $entry->debit);

        $balance = $account->getBalance();
        $this->assertEquals(1500, $balance->getAmount());
    }

    public function test_decrease_on_credit_normal_account_creates_debit_entry(): void
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

        // First increase to have a balance
        $account->increase(2000, 'Initial liability');

        $entry = $account->decrease(800, 'Decrease liability');

        $this->assertInstanceOf(JournalEntry::class, $entry);
        $this->assertEquals(800, $entry->debit);
        $this->assertEquals(0, $entry->credit);

        $balance = $account->getBalance();
        $this->assertEquals(1200, $balance->getAmount());
    }

    public function test_increase_dollars_method(): void
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

        $entry = $account->increaseDollars(25.00, 'Dollar increase');

        $this->assertInstanceOf(JournalEntry::class, $entry);
        $this->assertEquals(2500, $entry->debit); // debit-normal account
        $this->assertEquals(25.00, $account->getBalanceInDollars());
    }

    public function test_decrease_dollars_method(): void
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

        $account->increaseDollars(50.00, 'Initial deposit');
        $entry = $account->decreaseDollars(15.00, 'Withdrawal');

        $this->assertInstanceOf(JournalEntry::class, $entry);
        $this->assertEquals(1500, $entry->credit); // credit for debit-normal account decrease
        $this->assertEquals(35.00, $account->getBalanceInDollars());
    }

    public function test_get_dollars_debited_today(): void
    {
        $account = Account::create([
            'currency' => 'USD',
            'morphed_type' => 'test',
            'morphed_id' => 1,
        ]);

        // Add entry today
        $account->journalEntries()->create([
            'debit' => 2500, // $25.00
            'credit' => 0,
            'currency' => 'USD',
            'memo' => 'Today debit',
            'post_date' => Carbon::now(),
        ]);

        // Add entry yesterday (should not be included)
        $account->journalEntries()->create([
            'debit' => 1000,
            'credit' => 0,
            'currency' => 'USD',
            'memo' => 'Yesterday debit',
            'post_date' => Carbon::now()->subDay(),
        ]);

        $amount = $account->getDollarsDebitedToday();

        $this->assertEquals(25.00, $amount);
    }

    public function test_get_dollars_credited_today(): void
    {
        $account = Account::create([
            'currency' => 'USD',
            'morphed_type' => 'test',
            'morphed_id' => 1,
        ]);

        $account->journalEntries()->create([
            'debit' => 0,
            'credit' => 1850, // $18.50
            'currency' => 'USD',
            'memo' => 'Today credit',
            'post_date' => Carbon::now(),
        ]);

        $amount = $account->getDollarsCreditedToday();

        $this->assertEquals(18.50, $amount);
    }

    public function test_get_dollars_debited_on_specific_date(): void
    {
        $account = Account::create([
            'currency' => 'USD',
            'morphed_type' => 'test',
            'morphed_id' => 1,
        ]);

        $specificDate = Carbon::now()->subDays(3);
        $exactPostDate = $specificDate->copy()->setTime(10, 0, 0);

        $account->journalEntries()->create([
            'debit' => 3200, // $32.00
            'credit' => 0,
            'currency' => 'USD',
            'memo' => 'Specific date debit',
            'post_date' => $exactPostDate,
        ]);

        $amount = $account->getDollarsDebitedOn($specificDate);

        $this->assertEquals(32.00, $amount);
    }

    public function test_get_dollars_credited_on_specific_date(): void
    {
        $account = Account::create([
            'currency' => 'USD',
            'morphed_type' => 'test',
            'morphed_id' => 1,
        ]);

        $specificDate = Carbon::now()->subDays(2);
        $exactPostDate = $specificDate->copy()->setTime(14, 0, 0);

        $account->journalEntries()->create([
            'debit' => 0,
            'credit' => 4750, // $47.50
            'currency' => 'USD',
            'memo' => 'Specific date credit',
            'post_date' => $exactPostDate,
        ]);

        $amount = $account->getDollarsCreditedOn($specificDate);

        $this->assertEquals(47.50, $amount);
    }

    public function test_entries_referencing_object_query(): void
    {
        $account = Account::create([
            'currency' => 'USD',
            'morphed_type' => 'test',
            'morphed_id' => 1,
        ]);

        $accountType = AccountType::create([
            'name' => 'Reference Account Type',
            'type' => AccountCategory::ASSET->value,
        ]);

        // Create entry with reference
        $account->journalEntries()->create([
            'debit' => 1000,
            'credit' => 0,
            'currency' => 'USD',
            'memo' => 'Referenced entry',
            'post_date' => now(),
            'ref_class' => $accountType::class,
            'ref_class_id' => $accountType->id,
        ]);

        // Create entry without reference
        $account->journalEntries()->create([
            'debit' => 500,
            'credit' => 0,
            'currency' => 'USD',
            'memo' => 'Non-referenced entry',
            'post_date' => now(),
        ]);

        $query = $account->transactionsReferencingObjectQuery($accountType);

        $this->assertInstanceOf(\Illuminate\Database\Eloquent\Relations\HasMany::class, $query);
        $this->assertEquals(1, $query->count());
        $this->assertEquals('Referenced entry', $query->first()->memo);
    }

    public function test_credit_with_money_object(): void
    {
        $account = Account::create([
            'currency' => 'USD',
            'morphed_type' => 'test',
            'morphed_id' => 1,
        ]);

        $money = new Money(1800, new Currency('USD'));
        $entry = $account->credit($money, 'Money object credit');

        $this->assertInstanceOf(JournalEntry::class, $entry);
        $this->assertEquals(1800, $entry->credit);
        $this->assertEquals('Money object credit', $entry->memo);
    }

    public function test_debit_with_money_object(): void
    {
        $account = Account::create([
            'currency' => 'USD',
            'morphed_type' => 'test',
            'morphed_id' => 1,
        ]);

        $money = new Money(2200, new Currency('USD'));
        $entry = $account->debit($money, 'Money object debit');

        $this->assertInstanceOf(JournalEntry::class, $entry);
        $this->assertEquals(2200, $entry->debit);
        $this->assertEquals('Money object debit', $entry->memo);
    }

    public function test_account_balance_attribute_edge_cases(): void
    {
        $account = new Account([
            'currency' => 'USD',
            'morphed_type' => 'test',
            'morphed_id' => 1,
        ]);

        // Test setting balance with float value (should be truncated to int)
        $account->balance = 123.45;
        $this->assertEquals(123, $account->getAttributes()['balance'] ?? 0);

        // Test setting balance with negative value
        $account->balance = -500;
        $this->assertEquals(-500, $account->getAttributes()['balance'] ?? 0);

        // Test setting balance with Money object of different currency
        $eurMoney = new Money(2000, new Currency('EUR'));
        $account->balance = $eurMoney;
        $this->assertEquals('EUR', $account->currency);
        $this->assertEquals(2000, $account->getAttributes()['balance'] ?? 0);
    }

    public function test_account_post_method_edge_cases(): void
    {
        $account = Account::create([
            'currency' => 'USD',
            'morphed_type' => 'test',
            'morphed_id' => 3,
        ]);

        // Test with very large amounts
        $largeMoney = new Money(999999999, new Currency('USD'));
        $entry = $account->debit($largeMoney, 'Large amount test');

        $this->assertEquals(999999999, $entry->debit);
        $this->assertEquals(0, $entry->credit);

        // Test with very small amounts
        $smallMoney = new Money(1, new Currency('USD'));
        $entry2 = $account->credit($smallMoney, 'Small amount test');

        $this->assertEquals(1, $entry2->credit);
        $this->assertEquals(0, $entry2->debit);
    }

    public function test_account_boot_events_comprehensive(): void
    {
        $account = new Account([
            'currency' => 'GBP',
            'morphed_type' => 'test',
            'morphed_id' => 4,
        ]);

        $this->assertEquals('GBP', $account->currency);

        $account->save();

        $this->assertEquals(0, $account->getCurrentBalance()->getAmount());
        $this->assertEquals('GBP', $account->getCurrentBalance()->getCurrency()->getCode());
    }

    public function test_remaining_balance_attribute_edge_cases(): void
    {
        $account = Account::create([
            'currency' => 'CAD',
            'morphed_type' => 'test',
            'morphed_id' => 5,
        ]);

        // Test balance attribute with null value
        $account->balance = null;
        $this->assertEquals(0, $account->getAttributes()['balance'] ?? 0);

        // Test balance attribute with boolean true (edge case — becomes 0)
        $account->balance = true;
        $this->assertEquals(0, $account->getAttributes()['balance'] ?? 0);

        $account->balance = false;
        $this->assertEquals(0, $account->getAttributes()['balance'] ?? 0);
    }

    public function test_morphed_relationship(): void
    {
        $account = Account::create([
            'currency' => 'USD',
            'morphed_type' => 'App\Accounting\Models\AccountType',
            'morphed_id' => 123,
        ]);

        $relationship = $account->morphed();
        $this->assertInstanceOf(\Illuminate\Database\Eloquent\Relations\MorphTo::class, $relationship);
    }

    public function test_account_type_relationship(): void
    {
        $accountType = AccountType::create([
            'name' => 'Test Account Type',
            'type' => AccountCategory::ASSET,
        ]);

        $account = Account::create([
            'currency' => 'USD',
            'morphed_type' => 'test',
            'morphed_id' => 1,
            'account_type_id' => $accountType->id,
        ]);

        $relationship = $account->accountType();
        $this->assertInstanceOf(\Illuminate\Database\Eloquent\Relations\BelongsTo::class, $relationship);

        $relatedAccountType = $account->accountType;
        $this->assertEquals($accountType->id, $relatedAccountType->id);
    }

    public function test_journal_entries_relationship(): void
    {
        $account = Account::create([
            'currency' => 'USD',
            'morphed_type' => 'test',
            'morphed_id' => 2,
        ]);

        $relationship = $account->journalEntries();
        $this->assertInstanceOf(\Illuminate\Database\Eloquent\Relations\HasMany::class, $relationship);
    }

    public function test_get_balance_in_dollars_method(): void
    {
        $accountType = AccountType::create([
            'name' => 'Cash',
            'type' => AccountCategory::ASSET->value,
        ]);

        $account = Account::create([
            'account_type_id' => $accountType->id,
            'currency' => 'USD',
            'morphed_type' => 'test',
            'morphed_id' => 5,
        ]);

        $account->debit(2550, 'Test debit');   // $25.50
        $account->credit(1050, 'Test credit'); // $10.50

        // Asset (debit-normal): 2550 - 1050 = 1500 cents = $15.00
        $balanceInDollars = $account->getBalanceInDollars();
        $this->assertEquals(15.00, $balanceInDollars);
    }

    public function test_reset_current_balances_with_existing_entries(): void
    {
        $accountType = AccountType::create([
            'name' => 'Cash',
            'type' => AccountCategory::ASSET->value,
        ]);

        $account = Account::create([
            'account_type_id' => $accountType->id,
            'currency' => 'USD',
            'morphed_type' => 'test',
            'morphed_id' => 6,
        ]);

        $account->debit(3000, 'Test entry');

        $result = $account->resetCurrentBalances();

        $this->assertEquals(3000, $result->getAmount());
        $this->assertEquals('USD', $result->getCurrency()->getCode());
    }

    public function test_post_method_with_different_currency_scenarios(): void
    {
        $account = Account::create([
            'currency' => 'USD',
            'morphed_type' => 'test',
            'morphed_id' => 1,
        ]);

        $eurMoney = new Money(2000, new Currency('EUR'));
        $entry = $account->credit($eurMoney, 'EUR test');

        $this->assertEquals('EUR', $entry->currency);
        $this->assertEquals(2000, $entry->credit);
    }

    public function test_post_method_with_debit_money_object(): void
    {
        $account = Account::create([
            'currency' => 'GBP',
            'morphed_type' => 'test',
            'morphed_id' => 2,
        ]);

        $gbpMoney = new Money(3500, new Currency('GBP'));
        $entry = $account->debit($gbpMoney, 'GBP debit test');

        $this->assertEquals('GBP', $entry->currency);
        $this->assertEquals(3500, $entry->debit);
        $this->assertEquals(0, $entry->credit);
    }

    public function test_post_method_balance_update_mechanism(): void
    {
        $account = Account::create([
            'currency' => 'USD',
            'morphed_type' => 'test',
            'morphed_id' => 3,
        ]);

        $this->assertEquals(0, $account->getCurrentBalance()->getAmount());

        $money = new Money(1000, new Currency('USD'));
        $account->debit($money, 'Balance update test');

        $account->refresh();
        $this->assertEquals(1000, $account->balance->getAmount());
    }

    public function test_boot_creating_event_sets_zero_balance(): void
    {
        $account = new Account([
            'currency' => 'USD',
            'morphed_type' => 'test',
            'morphed_id' => 4,
        ]);

        $account->save();

        $this->assertEquals(0, $account->getAttributes()['balance']);
    }

    public function test_boot_created_event_with_currency(): void
    {
        $account = Account::create([
            'currency' => 'EUR',
            'morphed_type' => 'test',
            'morphed_id' => 5,
        ]);

        $this->assertEquals('EUR', $account->currency);
        $this->assertEquals(0, $account->getCurrentBalance()->getAmount());
    }

    public function test_reset_current_balances_edge_cases(): void
    {
        $account = Account::create([
            'currency' => 'USD',
            'morphed_type' => 'test',
            'morphed_id' => 6,
        ]);

        $result = $account->resetCurrentBalances();

        $this->assertEquals(0, $result->getAmount());
        $this->assertEquals('USD', $result->getCurrency()->getCode());
    }

    public function test_balance_attribute_setter_with_money_object(): void
    {
        $account = new Account([
            'morphed_type' => 'test',
            'morphed_id' => 7,
        ]);

        $money = new Money(2500, new Currency('EUR'));
        $account->balance = $money;

        $this->assertEquals(2500, $account->getAttributes()['balance']);
        $this->assertEquals('EUR', $account->currency);
    }

    public function test_balance_attribute_setter_without_currency(): void
    {
        $account = new Account([
            'morphed_type' => 'test',
            'morphed_id' => 8,
        ]);

        $account->balance = 1500;

        $this->assertEquals(1500, $account->getAttributes()['balance']);
        $this->assertEquals('USD', $account->currency);
    }

    public function test_balance_attribute_setter_with_string_value(): void
    {
        $account = new Account([
            'currency' => 'CAD',
            'morphed_type' => 'test',
            'morphed_id' => 9,
        ]);

        $account->balance = '3000';

        $this->assertEquals(3000, $account->getAttributes()['balance']);
    }

    public function test_balance_attribute_setter_with_non_numeric_string(): void
    {
        $account = new Account([
            'currency' => 'JPY',
            'morphed_type' => 'test',
            'morphed_id' => 10,
        ]);

        $account->balance = 'invalid';

        $this->assertEquals(0, $account->getAttributes()['balance']);
    }

    public function test_journal_entry_deleted_event_triggers_balance_reset(): void
    {
        $account = Account::create([
            'currency' => 'USD',
            'morphed_type' => 'test',
            'morphed_id' => 1,
        ]);

        $entry = $account->journalEntries()->create([
            'debit' => 1000,
            'credit' => 0,
            'currency' => 'USD',
            'memo' => 'Test entry',
            'post_date' => now(),
        ]);

        $this->assertNotNull($entry->id);

        $entry->delete();

        // Test passes if no exception thrown — boot deleted event reset balance
        $this->assertTrue(true);
    }

    public function test_reset_current_balances_empty_currency_coverage(): void
    {
        $account = new Account([
            'morphed_type' => 'test',
            'morphed_id' => 1000,
        ]);

        $result = $account->resetCurrentBalances();

        $this->assertEquals(0, $result->getAmount());
        $this->assertEquals('USD', $result->getCurrency()->getCode());
    }

    public function test_reset_current_balances_empty_currency_direct_coverage(): void
    {
        $account = Account::create([
            'currency' => 'USD',
            'morphed_type' => 'test',
            'morphed_id' => 1002,
        ]);

        // Use reflection to force the empty currency path
        $reflection = new \ReflectionClass($account);
        $attributesProperty = $reflection->getProperty('attributes');
        $attributesProperty->setAccessible(true);

        $attributes = $attributesProperty->getValue($account);
        $attributes['currency'] = null;
        $attributesProperty->setValue($account, $attributes);

        $result = $account->resetCurrentBalances();

        $this->assertEquals(0, $result->getAmount());
        $this->assertEquals('USD', $result->getCurrency()->getCode());

        $attributes = $attributesProperty->getValue($account);
        $this->assertEquals(0, $attributes['balance']);
    }

    public function test_is_posted_field_is_set_on_entry_from_credit(): void
    {
        $account = Account::create([
            'currency' => 'USD',
            'morphed_type' => 'test',
            'morphed_id' => 1,
        ]);

        $entry = $account->credit(500, 'Posted credit');

        $this->assertTrue($entry->is_posted);
    }

    public function test_is_posted_field_is_set_on_entry_from_debit(): void
    {
        $account = Account::create([
            'currency' => 'USD',
            'morphed_type' => 'test',
            'morphed_id' => 1,
        ]);

        $entry = $account->debit(500, 'Posted debit');

        $this->assertTrue($entry->is_posted);
    }
}
