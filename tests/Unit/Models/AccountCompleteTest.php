<?php

declare(strict_types=1);

namespace Tests\Unit\Models;

use Tests\Unit\TestCase;
use App\Accounting\Models\Account;
use App\Accounting\Models\AccountType;
use App\Accounting\Enums\AccountCategory;
use Money\Money;
use Money\Currency;

class AccountCompleteTest extends TestCase
{
    public function test_balance_attribute_getter_with_different_currencies(): void
    {
        $account = Account::create([
            'currency' => 'JPY',
            'morphed_type' => 'test',
            'morphed_id' => 1,
        ]);

        $account->setRawAttributes(array_merge($account->getAttributes(), ['balance' => 15000]));

        $balance = $account->balance;
        $this->assertEquals(15000, $balance->getAmount());
        $this->assertEquals('JPY', $balance->getCurrency()->getCode());
    }

    public function test_balance_attribute_setter_with_zero_value(): void
    {
        $account = new Account([
            'currency' => 'USD',
            'morphed_type' => 'test',
            'morphed_id' => 1,
        ]);

        $account->balance = 0;
        $this->assertEquals(0, $account->getAttributes()['balance']);
    }

    public function test_balance_attribute_setter_with_negative_string(): void
    {
        $account = new Account([
            'currency' => 'USD',
            'morphed_type' => 'test',
            'morphed_id' => 1,
        ]);

        $account->balance = '-500';
        $this->assertEquals(-500, $account->getAttributes()['balance']);
    }

    public function test_credit_and_debit_with_null_parameters(): void
    {
        $account = Account::create([
            'currency' => 'USD',
            'morphed_type' => 'test',
            'morphed_id' => 1,
        ]);

        // Test credit with minimal parameters (null memo, post_date, transaction_group)
        $creditEntry = $account->credit(1000);
        $this->assertEquals(1000, $creditEntry->credit);
        $this->assertNull($creditEntry->memo);
        $this->assertNull($creditEntry->transaction_group);

        // Test debit with minimal parameters
        $debitEntry = $account->debit(1500);
        $this->assertEquals(1500, $debitEntry->debit);
        $this->assertNull($debitEntry->memo);
        $this->assertNull($debitEntry->transaction_group);
    }

    public function test_dollar_methods_with_minimal_parameters(): void
    {
        $account = Account::create([
            'currency' => 'USD',
            'morphed_type' => 'test',
            'morphed_id' => 1,
        ]);

        // Test creditDollars with only amount (null memo, post_date)
        $creditEntry = $account->creditDollars(12.34);
        $this->assertEquals(1234, $creditEntry->credit);
        $this->assertNull($creditEntry->memo);

        // Test debitDollars with only amount
        $debitEntry = $account->debitDollars(56.78);
        $this->assertEquals(5678, $debitEntry->debit);
        $this->assertNull($debitEntry->memo);
    }

    public function test_post_method_indirectly_with_different_currencies(): void
    {
        $account = Account::create([
            'currency' => 'EUR',
            'morphed_type' => 'test',
            'morphed_id' => 1,
        ]);

        $money = new Money(2500, new Currency('EUR'));

        $entry = $account->credit($money, 'EUR test');

        $this->assertEquals(2500, $entry->credit);
        $this->assertEquals('EUR', $entry->currency);
        $this->assertEquals('EUR test', $entry->memo);
    }

    public function test_reset_current_balances_different_scenarios(): void
    {
        $accountType = AccountType::create([
            'name' => 'Cash',
            'type' => AccountCategory::ASSET->value,
        ]);

        $account = Account::create([
            'account_type_id' => $accountType->id,
            'currency' => 'EUR',
            'morphed_type' => 'test',
            'morphed_id' => 1,
        ]);

        $account->journalEntries()->createMany([
            [
                'debit' => 3000,
                'credit' => 0,
                'currency' => 'EUR',
                'memo' => 'Euro debit',
                'post_date' => now(),
            ],
            [
                'debit' => 0,
                'credit' => 1200,
                'currency' => 'EUR',
                'memo' => 'Euro credit',
                'post_date' => now(),
            ],
        ]);

        $result = $account->resetCurrentBalances();

        // Asset (debit-normal): 3000 - 1200 = 1800
        $this->assertEquals(1800, $result->getAmount());
        $this->assertEquals('EUR', $result->getCurrency()->getCode());
    }

    public function test_get_balance_on_edge_cases(): void
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

        // Test with future date (no entries should be included)
        $futureDate = \Carbon\Carbon::now()->addDays(10);
        $balance = $account->getBalanceOn($futureDate);

        $this->assertEquals(0, $balance->getAmount());

        // Add an entry and test again
        $account->journalEntries()->create([
            'debit' => 1000,
            'credit' => 0,
            'currency' => 'USD',
            'memo' => 'Test',
            'post_date' => now(),
        ]);

        $balance = $account->getBalanceOn($futureDate);
        // Asset (debit-normal): debit - credit = 1000 - 0 = 1000
        $this->assertEquals(1000, $balance->getAmount());
    }

    public function test_balance_sign_is_correct_for_income_account(): void
    {
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

        // Credit $500 to an income account — balance should be +500 (credit-normal)
        $account->journalEntries()->create([
            'debit' => 0,
            'credit' => 500,
            'currency' => 'USD',
            'memo' => 'Sales income',
            'post_date' => now(),
        ]);

        $balance = $account->getBalance();
        // Income (credit-normal): credits - debits = 500 - 0 = +500
        $this->assertEquals(500, $balance->getAmount());
    }

    public function test_balance_sign_is_correct_for_expense_account(): void
    {
        $accountType = AccountType::create([
            'name' => 'Rent Expense',
            'type' => AccountCategory::EXPENSE->value,
        ]);

        $account = Account::create([
            'account_type_id' => $accountType->id,
            'currency' => 'USD',
            'morphed_type' => 'test',
            'morphed_id' => 1,
        ]);

        // Debit $300 to an expense account — balance should be +300 (debit-normal)
        $account->journalEntries()->create([
            'debit' => 300,
            'credit' => 0,
            'currency' => 'USD',
            'memo' => 'Monthly rent',
            'post_date' => now(),
        ]);

        $balance = $account->getBalance();
        // Expense (debit-normal): debits - credits = 300 - 0 = +300
        $this->assertEquals(300, $balance->getAmount());
    }

    public function test_account_without_account_type_defaults_to_debit_normal(): void
    {
        // An account without an assigned account type should treat balance as debit-normal
        $account = Account::create([
            'currency' => 'USD',
            'morphed_type' => 'test',
            'morphed_id' => 1,
        ]);

        $account->journalEntries()->createMany([
            [
                'debit' => 1000,
                'credit' => 0,
                'currency' => 'USD',
                'memo' => 'Debit',
                'post_date' => now(),
            ],
            [
                'debit' => 0,
                'credit' => 400,
                'currency' => 'USD',
                'memo' => 'Credit',
                'post_date' => now(),
            ],
        ]);

        $balance = $account->getBalance();
        // Falls back to debit-normal: 1000 - 400 = 600
        $this->assertEquals(600, $balance->getAmount());
    }
}
