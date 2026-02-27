<?php

declare(strict_types=1);

namespace Tests\Functional;

use App\Accounting\Enums\AccountSubType;
use App\Accounting\Enums\AccountType;
use App\Accounting\Exceptions\InvalidAmountException;
use App\Accounting\Exceptions\UnbalancedTransactionException;
use App\Accounting\Models\Account;
use App\Accounting\Models\JournalEntry;
use App\Accounting\Services\TransactionBuilder;
use Carbon\Carbon;
use Money\Money;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class TransactionBuilderTest extends TestCase
{
    private Account $cash;
    private Account $revenue;
    private Account $expense;
    private Account $ar;
    private Account $ap;

    protected function setUp(): void
    {
        parent::setUp();

        $this->cash = Account::create(['name' => 'Cash', 'code' => '1000', 'type' => AccountType::ASSET]);
        $this->revenue = Account::create(['name' => 'Revenue', 'code' => '4000', 'type' => AccountType::INCOME]);
        $this->expense = Account::create(['name' => 'Rent', 'code' => '5000', 'type' => AccountType::EXPENSE]);
        $this->ar = Account::create(['name' => 'AR', 'code' => '1100', 'type' => AccountType::ASSET, 'sub_type' => AccountSubType::ACCOUNTS_RECEIVABLE]);
        $this->ap = Account::create(['name' => 'AP', 'code' => '2000', 'type' => AccountType::LIABILITY, 'sub_type' => AccountSubType::ACCOUNTS_PAYABLE]);
    }

    #[Test]
    public function it_creates_a_balanced_transaction(): void
    {
        $je = TransactionBuilder::create()
            ->date('2025-01-15')
            ->memo('Cash sale')
            ->reference('INV-001')
            ->debit($this->cash, 5000)
            ->credit($this->revenue, 5000)
            ->commit();

        $this->assertInstanceOf(JournalEntry::class, $je);
        $this->assertTrue($je->isBalanced());
        $this->assertEquals('Cash sale', $je->memo);
        $this->assertEquals('INV-001', $je->reference_number);
        $this->assertCount(2, $je->ledgerEntries);
    }

    #[Test]
    public function it_throws_on_unbalanced_transaction(): void
    {
        $this->expectException(UnbalancedTransactionException::class);

        TransactionBuilder::create()
            ->debit($this->cash, 5000)
            ->credit($this->revenue, 3000)
            ->commit();
    }

    #[Test]
    public function it_throws_on_zero_amount(): void
    {
        $this->expectException(InvalidAmountException::class);

        TransactionBuilder::create()
            ->debit($this->cash, 0)
            ->credit($this->revenue, 0)
            ->commit();
    }

    #[Test]
    public function it_throws_on_negative_amount(): void
    {
        $this->expectException(InvalidAmountException::class);

        TransactionBuilder::create()
            ->debit($this->cash, -100)
            ->credit($this->revenue, -100)
            ->commit();
    }

    #[Test]
    public function it_updates_account_balances_after_commit(): void
    {
        TransactionBuilder::create()
            ->debit($this->cash, 10000)
            ->credit($this->revenue, 10000)
            ->commit();

        $this->cash->refresh();
        $this->revenue->refresh();

        $this->assertEquals(10000, (int) $this->cash->getBalance()->getAmount());
        $this->assertEquals(10000, (int) $this->revenue->getBalance()->getAmount());
    }

    #[Test]
    public function it_supports_money_objects(): void
    {
        $je = TransactionBuilder::create()
            ->debit($this->cash, Money::USD(7500))
            ->credit($this->revenue, Money::USD(7500))
            ->commit();

        $this->assertTrue($je->isBalanced());
        $this->assertEquals(7500, $je->totalDebits());
    }

    #[Test]
    public function it_supports_dollar_amounts(): void
    {
        $je = TransactionBuilder::create()
            ->debitDollars($this->cash, 150.75)
            ->creditDollars($this->revenue, 150.75)
            ->commit();

        $this->assertTrue($je->isBalanced());
        $this->assertEquals(15075, $je->totalDebits());
    }

    #[Test]
    public function it_supports_increase_decrease(): void
    {
        // Increase cash (asset, debit-normal) → should create debit
        // Increase revenue (income, credit-normal) → should create credit
        $je = TransactionBuilder::create()
            ->increase($this->cash, 5000)
            ->increase($this->revenue, 5000)
            ->commit();

        $this->assertTrue($je->isBalanced());

        $this->cash->refresh();
        $this->revenue->refresh();

        $this->assertEquals(5000, (int) $this->cash->getBalance()->getAmount());
        $this->assertEquals(5000, (int) $this->revenue->getBalance()->getAmount());
    }

    #[Test]
    public function it_supports_decrease(): void
    {
        // First, establish balances
        TransactionBuilder::create()
            ->debit($this->cash, 10000)
            ->credit($this->revenue, 10000)
            ->commit();

        // Now decrease both
        $je = TransactionBuilder::create()
            ->decrease($this->cash, 3000)
            ->decrease($this->revenue, 3000)
            ->commit();

        $this->assertTrue($je->isBalanced());

        $this->cash->refresh();
        $this->revenue->refresh();

        $this->assertEquals(7000, (int) $this->cash->getBalance()->getAmount());
        $this->assertEquals(7000, (int) $this->revenue->getBalance()->getAmount());
    }

    #[Test]
    public function it_supports_multi_line_transactions(): void
    {
        // Split payment: partial cash, partial on credit
        $je = TransactionBuilder::create()
            ->memo('Split payment')
            ->debit($this->cash, 3000)
            ->debit($this->ar, 2000)
            ->credit($this->revenue, 5000)
            ->commit();

        $this->assertTrue($je->isBalanced());
        $this->assertCount(3, $je->ledgerEntries);
        $this->assertEquals(5000, $je->totalDebits());
        $this->assertEquals(5000, $je->totalCredits());
    }

    #[Test]
    public function it_accepts_references_on_entries(): void
    {
        // Use the AR account as a "referenced model" for testing
        $invoiceModel = Account::create(['name' => 'Invoice Ref', 'type' => AccountType::ASSET]);

        $je = TransactionBuilder::create()
            ->debit($this->ar, 5000, 'Invoice #101', $invoiceModel)
            ->credit($this->revenue, 5000)
            ->commit();

        $arEntry = $je->ledgerEntries->where('account_id', $this->ar->id)->first();

        $this->assertEquals(get_class($invoiceModel), $arEntry->ledgerable_type);
        $this->assertEquals($invoiceModel->id, $arEntry->ledgerable_id);
    }

    #[Test]
    public function it_uses_per_entry_memo(): void
    {
        $je = TransactionBuilder::create()
            ->memo('Transaction memo')
            ->debit($this->cash, 5000, 'Cash received')
            ->credit($this->revenue, 5000, 'Service performed')
            ->commit();

        $entries = $je->ledgerEntries;
        $cashEntry = $entries->where('account_id', $this->cash->id)->first();
        $revenueEntry = $entries->where('account_id', $this->revenue->id)->first();

        $this->assertEquals('Cash received', $cashEntry->memo);
        $this->assertEquals('Service performed', $revenueEntry->memo);
    }

    #[Test]
    public function entry_without_memo_falls_back_to_transaction_memo(): void
    {
        $je = TransactionBuilder::create()
            ->memo('Transaction-level memo')
            ->debit($this->cash, 5000)
            ->credit($this->revenue, 5000)
            ->commit();

        $entries = $je->ledgerEntries;
        foreach ($entries as $entry) {
            $this->assertEquals('Transaction-level memo', $entry->memo);
        }
    }

    #[Test]
    public function it_can_inspect_pending_entries(): void
    {
        $builder = TransactionBuilder::create()
            ->debit($this->cash, 5000)
            ->credit($this->revenue, 5000);

        $pending = $builder->getPendingEntries();
        $this->assertCount(2, $pending);
        $this->assertEquals(5000, $pending[0]['debit']);
        $this->assertEquals(5000, $pending[1]['credit']);
    }

    #[Test]
    public function it_sets_date_from_carbon_instance(): void
    {
        $je = TransactionBuilder::create()
            ->date(Carbon::parse('2025-06-15'))
            ->debit($this->cash, 5000)
            ->credit($this->revenue, 5000)
            ->commit();

        $this->assertEquals('2025-06-15', $je->date->toDateString());
    }

    #[Test]
    public function it_sets_date_from_string(): void
    {
        $je = TransactionBuilder::create()
            ->date('2025-03-20')
            ->debit($this->cash, 5000)
            ->credit($this->revenue, 5000)
            ->commit();

        $this->assertEquals('2025-03-20', $je->date->toDateString());
    }

    #[Test]
    public function it_creates_draft_transaction(): void
    {
        $je = TransactionBuilder::create()
            ->draft()
            ->debit($this->cash, 5000)
            ->credit($this->revenue, 5000)
            ->commit();

        $this->assertFalse($je->is_posted);

        foreach ($je->ledgerEntries as $entry) {
            $this->assertFalse($entry->is_posted);
        }

        // Account balances should NOT be affected
        $this->cash->refresh();
        $this->revenue->refresh();
        $this->assertEquals(0, $this->cash->cached_balance);
        $this->assertEquals(0, $this->revenue->cached_balance);
    }

    #[Test]
    public function draft_transaction_can_be_posted_later(): void
    {
        $je = TransactionBuilder::create()
            ->draft()
            ->debit($this->cash, 5000)
            ->credit($this->revenue, 5000)
            ->commit();

        $this->assertFalse($je->is_posted);

        // Draft entries should have running_balance = 0
        foreach ($je->ledgerEntries as $entry) {
            $this->assertEquals(0, $entry->running_balance);
        }

        // Post the draft
        $je->post();

        $this->assertTrue($je->is_posted);

        // Running balances should now be computed
        $cashEntry = $je->ledgerEntries()->where('account_id', $this->cash->id)->first();
        $this->assertEquals(5000, $cashEntry->running_balance);

        // Balances should now reflect the transaction
        $this->cash->refresh();
        $this->revenue->refresh();
        $this->assertEquals(5000, $this->cash->cached_balance);
        $this->assertEquals(5000, $this->revenue->cached_balance);
    }

    #[Test]
    public function draft_entries_excluded_from_reports(): void
    {
        // Post a real transaction
        TransactionBuilder::create()
            ->debit($this->cash, 10000)
            ->credit($this->revenue, 10000)
            ->commit();

        // Create a draft transaction
        TransactionBuilder::create()
            ->draft()
            ->debit($this->cash, 5000)
            ->credit($this->revenue, 5000)
            ->commit();

        // Balance should only reflect the posted transaction
        $this->cash->refresh();
        $this->assertEquals(10000, $this->cash->cached_balance);
    }
}
