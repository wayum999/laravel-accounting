<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use Carbon\Carbon;
use Money\Money;
use Money\Currency;
use Illuminate\Support\Str;
use App\Accounting\Models\Account;
use App\Accounting\Models\JournalEntry;
use App\Accounting\Transaction;
use App\Accounting\Exceptions\InvalidJournalEntryValue;
use App\Accounting\Exceptions\InvalidJournalMethod;
use App\Accounting\Exceptions\DebitsAndCreditsDoNotEqual;
use Tests\TestCase;
use Illuminate\Support\Facades\DB;

class TransactionTest extends TestCase
{
    public function testNewDoubleEntryTransactionGroup()
    {
        $transaction = Transaction::newDoubleEntryTransactionGroup();
        $this->assertInstanceOf(Transaction::class, $transaction);
        $this->assertEmpty($transaction->getTransactionsPending());
    }

    public function testAddTransactionWithCredit()
    {
        $transaction = Transaction::newDoubleEntryTransactionGroup();
        $account = Account::create([
            'account_type_id' => null,
            'currency' => 'USD',
            'morphed_type' => 'test',
            'morphed_id' => 1,
        ]);

        $money = new Money(1000, new Currency('USD'));

        $transaction->addTransaction($account, 'credit', $money, 'Test credit');

        $transactions = $transaction->getTransactionsPending();
        $this->assertCount(1, $transactions);
        $this->assertEquals('credit', $transactions[0]['method']);
        $this->assertEquals(1000, $transactions[0]['money']->getAmount());
        $this->assertEquals('Test credit', $transactions[0]['memo']);
    }

    public function testAddTransactionWithDebit()
    {
        $transaction = Transaction::newDoubleEntryTransactionGroup();
        $account = Account::create([
            'account_type_id' => null,
            'currency' => 'USD',
            'morphed_type' => 'test',
            'morphed_id' => 2,
        ]);

        $money = new Money(1500, new Currency('USD'));

        $transaction->addTransaction($account, 'debit', $money, 'Test debit');

        $transactions = $transaction->getTransactionsPending();
        $this->assertCount(1, $transactions);
        $this->assertEquals('debit', $transactions[0]['method']);
        $this->assertEquals(1500, $transactions[0]['money']->getAmount());
    }

    public function testAddTransactionWithInvalidMethod()
    {
        $this->expectException(InvalidJournalMethod::class);

        $transaction = Transaction::newDoubleEntryTransactionGroup();
        $account = Account::create([
            'account_type_id' => null,
            'currency' => 'USD',
            'morphed_type' => 'test',
            'morphed_id' => 3,
        ]);

        $money = new Money(1000, new Currency('USD'));
        $transaction->addTransaction($account, 'invalid_method', $money);
    }

    public function testAddTransactionWithZeroAmount()
    {
        $this->expectException(InvalidJournalEntryValue::class);

        $transaction = Transaction::newDoubleEntryTransactionGroup();
        $account = Account::create([
            'account_type_id' => null,
            'currency' => 'USD',
            'morphed_type' => 'test',
            'morphed_id' => 4,
        ]);

        $money = new Money(0, new Currency('USD'));
        $transaction->addTransaction($account, 'credit', $money);
    }

    public function testAddDollarTransaction()
    {
        $transaction = Transaction::newDoubleEntryTransactionGroup();
        $account = Account::create([
            'account_type_id' => null,
            'currency' => 'USD',
            'morphed_type' => 'test',
            'morphed_id' => 5,
        ]);

        $transaction->addDollarTransaction($account, 'credit', 10.50, 'Test dollar transaction');

        $transactions = $transaction->getTransactionsPending();
        $this->assertCount(1, $transactions);
        $this->assertEquals(1050, $transactions[0]['money']->getAmount()); // $10.50 should be 1050 cents
    }

    public function testCommitWithUnbalancedTransactions()
    {
        $this->expectException(DebitsAndCreditsDoNotEqual::class);

        $transaction = Transaction::newDoubleEntryTransactionGroup();
        $account = Account::create([
            'account_type_id' => null,
            'balance' => 0,
            'currency' => 'USD',
            'morphed_type' => 'test',
            'morphed_id' => 6,
        ]);

        $money = new Money(1000, new Currency('USD'));
        $transaction->addTransaction($account, 'credit', $money);

        // Only a credit, no matching debit
        $transaction->commit();
    }

    public function testCommitWithBalancedTransactions()
    {
        $transaction = Transaction::newDoubleEntryTransactionGroup();

        // Create two accounts
        $account1 = Account::create([
            'account_type_id' => null,
            'balance' => 0,
            'currency' => 'USD',
            'morphed_type' => 'test',
            'morphed_id' => 7,
        ]);

        $account2 = Account::create([
            'account_type_id' => null,
            'balance' => 0,
            'currency' => 'USD',
            'morphed_type' => 'test',
            'morphed_id' => 8,
        ]);

        $money = new Money(1000, new Currency('USD'));
        $transaction->addTransaction($account1, 'debit', $money, 'Test debit');
        $transaction->addTransaction($account2, 'credit', $money, 'Test credit');

        $transactionGroupId = $transaction->commit();

        // Verify transaction group ID is a valid UUID
        $this->assertTrue(Str::isUuid($transactionGroupId));

        // Refresh accounts to get updated balances
        $account1->refresh();
        $account2->refresh();

        // Without an account type assigned, getBalance() falls back to debit-normal (debits - credits).
        // account1 received a debit of 1000: balance = 1000 - 0 = 1000
        // account2 received a credit of 1000: balance = 0 - 1000 = -1000
        $this->assertEquals(1000, $account1->balance->getAmount(), 'Debit should increase balance');
        $this->assertEquals(-1000, $account2->balance->getAmount(), 'Credit should decrease balance');
    }

    public function testAddTransactionWithPostDate()
    {
        $transaction = Transaction::newDoubleEntryTransactionGroup();
        $account = Account::create([
            'account_type_id' => null,
            'currency' => 'USD',
            'morphed_type' => 'test',
            'morphed_id' => 9,
        ]);

        $money = new Money(1200, new Currency('USD'));
        $postDate = Carbon::now()->subDays(5);

        $transaction->addTransaction($account, 'credit', $money, 'Test with post date', null, $postDate);

        $transactions = $transaction->getTransactionsPending();
        $this->assertCount(1, $transactions);
        $this->assertEquals($postDate, $transactions[0]['postdate']);
    }

    public function testAddDollarTransactionWithPostDate()
    {
        $transaction = Transaction::newDoubleEntryTransactionGroup();
        $account = Account::create([
            'account_type_id' => null,
            'currency' => 'USD',
            'morphed_type' => 'test',
            'morphed_id' => 10,
        ]);

        $postDate = Carbon::now()->subDays(2);
        $transaction->addDollarTransaction($account, 'debit', 25.75, 'Dollar transaction with date', null, $postDate);

        $transactions = $transaction->getTransactionsPending();
        $this->assertCount(1, $transactions);
        $this->assertEquals(2575, $transactions[0]['money']->getAmount()); // $25.75 = 2575 cents
        $this->assertEquals($postDate, $transactions[0]['postdate']);
    }

    public function testCommitWithReferencedObjects()
    {
        $transaction = Transaction::newDoubleEntryTransactionGroup();

        // Create accounts
        $account1 = Account::create([
            'account_type_id' => null,
            'currency' => 'USD',
            'morphed_type' => 'test',
            'morphed_id' => 11,
        ]);

        $account2 = Account::create([
            'account_type_id' => null,
            'currency' => 'USD',
            'morphed_type' => 'test',
            'morphed_id' => 12,
        ]);

        // Create a reference object (using account2 as reference)
        $referenceObject = $account2;

        $money = new Money(1500, new Currency('USD'));
        $transaction->addTransaction($account1, 'debit', $money, 'Referenced debit', $referenceObject);
        $transaction->addTransaction($account2, 'credit', $money, 'Referenced credit');

        $transactionGroupId = $transaction->commit();

        // Verify transaction was created with reference
        $createdEntry = JournalEntry::where('transaction_group', $transactionGroupId)
            ->where('account_id', $account1->id)
            ->first();

        $this->assertNotNull($createdEntry);
        $this->assertEquals(get_class($referenceObject), $createdEntry->ref_class);
        $this->assertEquals($referenceObject->id, $createdEntry->ref_class_id);
    }

    public function testGetTransactionsPendingReturnsCorrectStructure()
    {
        $transaction = Transaction::newDoubleEntryTransactionGroup();
        $account = Account::create([
            'account_type_id' => null,
            'currency' => 'USD',
            'morphed_type' => 'test',
            'morphed_id' => 13,
        ]);

        $money = new Money(3000, new Currency('USD'));
        $postDate = Carbon::now();
        $referenceObject = $account; // Self-reference for testing

        $transaction->addTransaction($account, 'credit', $money, 'Structured test', $referenceObject, $postDate);

        $transactions = $transaction->getTransactionsPending();
        $this->assertCount(1, $transactions);

        $pendingTransaction = $transactions[0];
        $this->assertArrayHasKey('account', $pendingTransaction);
        $this->assertArrayHasKey('method', $pendingTransaction);
        $this->assertArrayHasKey('money', $pendingTransaction);
        $this->assertArrayHasKey('memo', $pendingTransaction);
        $this->assertArrayHasKey('postdate', $pendingTransaction);
        $this->assertArrayHasKey('referencedObject', $pendingTransaction);

        $this->assertTrue($pendingTransaction['account']->is($account));
        $this->assertEquals('credit', $pendingTransaction['method']);
        $this->assertEquals(3000, $pendingTransaction['money']->getAmount());
        $this->assertEquals('Structured test', $pendingTransaction['memo']);
        $this->assertEquals($postDate, $pendingTransaction['postdate']);
        $this->assertTrue($pendingTransaction['referencedObject']->is($referenceObject));
    }
}
