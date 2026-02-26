<?php

declare(strict_types=1);

namespace Tests\Functional;

use Carbon\Carbon;
use Money\Currency;
use Money\Money;
use App\Accounting\Models\Account;
use App\Accounting\Models\AccountType;
use App\Accounting\Models\JournalEntry;
use App\Accounting\Transaction;
use Tests\TestCase;

class AccountingIntegrationTest extends TestCase
{
    public function test_complete_transaction_flow_coverage(): void
    {
        // Test a complete transaction flow to ensure all code paths are hit
        $transaction = Transaction::newDoubleEntryTransactionGroup();

        $account1 = Account::create([
            'currency' => 'USD',
            'morphed_type' => 'test',
            'morphed_id' => 1,
        ]);

        $account2 = Account::create([
            'currency' => 'USD',
            'morphed_type' => 'test',
            'morphed_id' => 2,
        ]);

        // Create a complex transaction with all features
        $money1 = new Money(1500, new Currency('USD'));
        $money2 = new Money(1500, new Currency('USD'));

        // Add transactions with all possible parameters
        $transaction->addTransaction(
            $account1,
            'debit',
            $money1,
            'Complete test debit',
            $account2, // reference object
            \Carbon\Carbon::now()->subHours(2)
        );

        $transaction->addTransaction(
            $account2,
            'credit',
            $money2,
            'Complete test credit',
            $account1, // reference object
            \Carbon\Carbon::now()->subHours(1)
        );

        // This should exercise all code paths in commit()
        $transactionId = $transaction->commit();

        $this->assertIsString($transactionId);
        $this->assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', $transactionId);

        // Verify the entries were created with references
        $createdEntries = JournalEntry::where('transaction_group', $transactionId)->get();
        $this->assertCount(2, $createdEntries);

        // Check that references were set
        $debitEntry = $createdEntries->where('account_id', $account1->id)->first();
        $this->assertEquals($account2::class, $debitEntry->ref_class);
        $this->assertEquals($account2->id, $debitEntry->ref_class_id);
    }

    public function testBasicAccountTransactions()
    {
        // Create account types
        $cashAccountType = AccountType::create([
            'name' => 'Cash Account',
            'type' => 'asset',
        ]);

        $incomeAccountType = AccountType::create([
            'name' => 'Service Revenue',
            'type' => 'income',
        ]);

        // Create accounts linked to their account types
        $cashAccount = $cashAccountType->accounts()->create([
            'balance' => 0,
            'currency' => 'USD',
            'morphed_type' => 'test',
            'morphed_id' => 1,
        ]);

        $incomeAccount = $incomeAccountType->accounts()->create([
            'balance' => 0,
            'currency' => 'USD',
            'morphed_type' => 'test',
            'morphed_id' => 2,
        ]);

        // Create an additional income account
        $incomeAccountType->accounts()->create([
            'currency' => 'USD',
            'morphed_type' => 'test',
            'morphed_id' => 3,
            'balance' => 0,
        ]);

        // Initial balance check
        $this->assertEquals(0, $cashAccount->getCurrentBalanceInDollars());
        $this->assertEquals(0, $incomeAccount->getCurrentBalanceInDollars());

        // Record a service revenue transaction
        $transaction = Transaction::newDoubleEntryTransactionGroup();

        // Debit cash (asset increases with debit)
        $transaction->addDollarTransaction(
            $cashAccount,
            'debit',
            150.00,
            'Service revenue received',
            null,
            Carbon::now()
        );

        // Credit income (income increases with credit)
        $transaction->addDollarTransaction(
            $incomeAccount,
            'credit',
            150.00,
            'Service revenue earned',
            null,
            Carbon::now()
        );

        // Commit the transaction group
        $transaction->commit();

        // Refresh accounts to get updated balances
        $cashAccount->refresh();
        $incomeAccount->refresh();

        // Verify balances using the new sign convention:
        // Asset (debit-normal): balance = debits - credits = 150 - 0 = +150
        // Income (credit-normal): balance = credits - debits = 150 - 0 = +150
        $this->assertEquals(150.00, $cashAccount->getCurrentBalanceInDollars(), 'Debit should increase asset balance (positive balance)');
        $this->assertEquals(150.00, $incomeAccount->getCurrentBalanceInDollars(), 'Credit should increase income balance (positive balance)');

        // Verify entries were recorded
        $this->assertCount(1, $cashAccount->journalEntries);
        $this->assertCount(1, $incomeAccount->journalEntries);
    }

    public function testExpenseTransaction()
    {
        // Create account types
        $cashAccountType = AccountType::create(['name' => 'Cash', 'type' => 'asset']);
        $expenseAccountType = AccountType::create(['name' => 'Office Supplies', 'type' => 'expense']);
        $equityAccountType = AccountType::create(['name' => 'Owner\'s Equity', 'type' => 'equity']);

        // Initialize accounts linked to their account types
        $cashAccount = $cashAccountType->accounts()->create([
            'balance' => 0,
            'currency' => 'USD',
            'morphed_type' => 'test',
            'morphed_id' => 1,
        ]);

        $equityAccount = $equityAccountType->accounts()->create([
            'balance' => 0,
            'currency' => 'USD',
            'morphed_type' => 'test',
            'morphed_id' => 2,
        ]);

        $expenseAccount = $expenseAccountType->accounts()->create([
            'balance' => 0,
            'currency' => 'USD',
            'morphed_type' => 'test',
            'morphed_id' => 3,
        ]);

        // Initial investment: Debit cash, credit owner's equity
        $transaction = Transaction::newDoubleEntryTransactionGroup();

        // Debit cash (asset increases)
        $transaction->addDollarTransaction(
            $cashAccount,
            'debit',
            1000.00,
            'Initial investment',
            null,
            Carbon::now()
        );

        // Credit owner's equity (equity increases)
        $transaction->addDollarTransaction(
            $equityAccount,
            'credit',
            1000.00,
            'Owner\'s equity',
            null,
            Carbon::now()
        );

        $transaction->commit();

        // Record an expense transaction
        $transaction = Transaction::newDoubleEntryTransactionGroup();

        // Debit expense (expense increases)
        $transaction->addDollarTransaction(
            $expenseAccount,
            'debit',
            75.50,
            'Office supplies purchase',
            null,
            Carbon::now()
        );

        // Credit cash (asset decreases)
        $transaction->addDollarTransaction(
            $cashAccount,
            'credit',
            75.50,
            'Paid for office supplies',
            null,
            Carbon::now()
        );

        $transaction->commit();

        // Refresh accounts
        $cashAccount->refresh();
        $expenseAccount->refresh();

        // Refresh account types to get updated balances
        $cashAccountType->refresh();
        $expenseAccountType->refresh();
        $equityAccountType->refresh();

        // Check cash account type balance (asset, debit-normal):
        // Initial: +1000.00 (debit)
        // Expense: -75.50 (credit)
        // Expected: 1000.00 - 75.50 = 924.50
        $this->assertEquals(924.50, $cashAccountType->getCurrentBalanceInDollars(), 'Cash account type balance should be reduced by expense');

        // Check expense account type balance (expense, debit-normal):
        // Expense: +75.50 (debit)
        // Expected: 75.50
        $this->assertEquals(75.50, $expenseAccountType->getCurrentBalanceInDollars(), 'Expense account type should show the expense amount');

        // Check equity account type balance (equity, credit-normal):
        // Initial: +1000.00 (credit)
        // No changes
        // Expected: 1000.00
        $this->assertEquals(1000.00, $equityAccountType->getCurrentBalanceInDollars(), 'Equity account type balance should remain unchanged');
    }
}
