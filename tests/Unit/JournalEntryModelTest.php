<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Accounting\Enums\AccountType;
use App\Accounting\Models\Account;
use App\Accounting\Models\JournalEntry;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class JournalEntryModelTest extends TestCase
{
    #[Test]
    public function it_generates_uuid_on_creation(): void
    {
        $entry = JournalEntry::create([
            'date' => '2025-01-15',
            'memo' => 'Test entry',
        ]);

        $this->assertNotNull($entry->id);
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/',
            $entry->id,
        );
    }

    #[Test]
    public function it_calculates_total_debits_and_credits(): void
    {
        $cash = Account::create(['name' => 'Cash', 'type' => AccountType::ASSET]);
        $revenue = Account::create(['name' => 'Revenue', 'type' => AccountType::REVENUE]);

        $je = JournalEntry::create([
            'date' => '2025-01-15',
            'memo' => 'Sale',
        ]);

        $je->ledgerEntries()->create([
            'account_id' => $cash->id,
            'debit' => 5000,
            'credit' => 0,
            'currency' => 'USD',
            'post_date' => now(),
        ]);

        $je->ledgerEntries()->create([
            'account_id' => $revenue->id,
            'debit' => 0,
            'credit' => 5000,
            'currency' => 'USD',
            'post_date' => now(),
        ]);

        $this->assertEquals(5000, $je->totalDebits());
        $this->assertEquals(5000, $je->totalCredits());
        $this->assertTrue($je->isBalanced());
    }

    #[Test]
    public function it_detects_unbalanced_entries(): void
    {
        $cash = Account::create(['name' => 'Cash', 'type' => AccountType::ASSET]);

        $je = JournalEntry::create([
            'date' => '2025-01-15',
            'memo' => 'Broken entry',
        ]);

        $je->ledgerEntries()->create([
            'account_id' => $cash->id,
            'debit' => 5000,
            'credit' => 0,
            'currency' => 'USD',
            'post_date' => now(),
        ]);

        $this->assertFalse($je->isBalanced());
    }

    #[Test]
    public function it_reverses_a_journal_entry(): void
    {
        $cash = Account::create(['name' => 'Cash', 'type' => AccountType::ASSET]);
        $revenue = Account::create(['name' => 'Revenue', 'type' => AccountType::REVENUE]);

        $je = JournalEntry::create([
            'date' => '2025-01-15',
            'reference_number' => 'INV-001',
            'memo' => 'Sale',
        ]);

        $je->ledgerEntries()->create([
            'account_id' => $cash->id,
            'debit' => 5000,
            'credit' => 0,
            'currency' => 'USD',
            'post_date' => '2025-01-15',
        ]);

        $je->ledgerEntries()->create([
            'account_id' => $revenue->id,
            'debit' => 0,
            'credit' => 5000,
            'currency' => 'USD',
            'post_date' => '2025-01-15',
        ]);

        $reversal = $je->reverse('Reversal of sale');

        $this->assertTrue($reversal->isBalanced());
        $this->assertStringContainsString('Reversal of sale', $reversal->memo);

        // The reversal should have swapped debits and credits
        $reversalEntries = $reversal->ledgerEntries;
        $cashReversal = $reversalEntries->where('account_id', $cash->id)->first();
        $revenueReversal = $reversalEntries->where('account_id', $revenue->id)->first();

        $this->assertEquals(0, $cashReversal->debit);
        $this->assertEquals(5000, $cashReversal->credit);
        $this->assertEquals(5000, $revenueReversal->debit);
        $this->assertEquals(0, $revenueReversal->credit);

        // Net effect on accounts should be zero
        $cash->refresh();
        $revenue->refresh();
        $this->assertEquals(0, (int) $cash->getBalance()->getAmount());
        $this->assertEquals(0, (int) $revenue->getBalance()->getAmount());
    }

    #[Test]
    public function it_voids_a_journal_entry(): void
    {
        $cash = Account::create(['name' => 'Cash', 'type' => AccountType::ASSET]);
        $expense = Account::create(['name' => 'Rent', 'type' => AccountType::EXPENSE]);

        $je = JournalEntry::create([
            'date' => '2025-01-15',
            'memo' => 'Rent payment',
        ]);

        $je->ledgerEntries()->create([
            'account_id' => $expense->id,
            'debit' => 100000,
            'credit' => 0,
            'currency' => 'USD',
            'post_date' => '2025-01-15',
        ]);

        $je->ledgerEntries()->create([
            'account_id' => $cash->id,
            'debit' => 0,
            'credit' => 100000,
            'currency' => 'USD',
            'post_date' => '2025-01-15',
        ]);

        $void = $je->void();

        $this->assertStringContainsString('VOID:', $void->memo);
        $this->assertTrue($void->isBalanced());

        // Net effect: zero
        $cash->refresh();
        $expense->refresh();
        $this->assertEquals(0, (int) $cash->getBalance()->getAmount());
        $this->assertEquals(0, (int) $expense->getBalance()->getAmount());
    }

    #[Test]
    public function it_has_ledger_entries_relationship(): void
    {
        $account = Account::create(['name' => 'Cash', 'type' => AccountType::ASSET]);

        $je = JournalEntry::create([
            'date' => '2025-01-15',
            'memo' => 'Test',
        ]);

        $je->ledgerEntries()->create([
            'account_id' => $account->id,
            'debit' => 1000,
            'credit' => 0,
            'currency' => 'USD',
            'post_date' => now(),
        ]);

        $this->assertCount(1, $je->ledgerEntries);
    }

    #[Test]
    public function it_can_post_an_unposted_journal_entry(): void
    {
        $cash = Account::create(['name' => 'Cash', 'type' => AccountType::ASSET]);
        $revenue = Account::create(['name' => 'Revenue', 'type' => AccountType::REVENUE]);

        $je = JournalEntry::create([
            'date' => '2025-01-15',
            'memo' => 'Draft sale',
            'is_posted' => false,
        ]);

        $je->ledgerEntries()->create([
            'account_id' => $cash->id,
            'debit' => 5000,
            'credit' => 0,
            'currency' => 'USD',
            'post_date' => '2025-01-15',
            'is_posted' => false,
        ]);

        $je->ledgerEntries()->create([
            'account_id' => $revenue->id,
            'debit' => 0,
            'credit' => 5000,
            'currency' => 'USD',
            'post_date' => '2025-01-15',
            'is_posted' => false,
        ]);

        // Before posting: balances should be zero
        $this->assertEquals(0, (int) $cash->getBalance()->getAmount());
        $this->assertEquals(0, (int) $revenue->getBalance()->getAmount());

        // Post the journal entry
        $je->post();

        $this->assertTrue($je->is_posted);

        // All ledger entries should now be posted
        foreach ($je->ledgerEntries()->get() as $entry) {
            $this->assertTrue($entry->is_posted);
        }

        // Balances should now reflect the entries
        $cash->refresh();
        $revenue->refresh();
        $this->assertEquals(5000, (int) $cash->getBalance()->getAmount());
        $this->assertEquals(5000, (int) $revenue->getBalance()->getAmount());
    }

    #[Test]
    public function it_can_unpost_a_posted_journal_entry(): void
    {
        $cash = Account::create(['name' => 'Cash', 'type' => AccountType::ASSET]);
        $revenue = Account::create(['name' => 'Revenue', 'type' => AccountType::REVENUE]);

        $je = JournalEntry::create([
            'date' => '2025-01-15',
            'memo' => 'Sale',
            'is_posted' => true,
        ]);

        $je->ledgerEntries()->create([
            'account_id' => $cash->id,
            'debit' => 5000,
            'credit' => 0,
            'currency' => 'USD',
            'post_date' => '2025-01-15',
        ]);

        $je->ledgerEntries()->create([
            'account_id' => $revenue->id,
            'debit' => 0,
            'credit' => 5000,
            'currency' => 'USD',
            'post_date' => '2025-01-15',
        ]);

        // Before unposting: balances should reflect entries
        $cash->refresh();
        $revenue->refresh();
        $this->assertEquals(5000, (int) $cash->getBalance()->getAmount());
        $this->assertEquals(5000, (int) $revenue->getBalance()->getAmount());

        // Unpost
        $je->unpost();

        $this->assertFalse($je->is_posted);

        foreach ($je->ledgerEntries()->get() as $entry) {
            $this->assertFalse($entry->is_posted);
        }

        // Balances should now be zero
        $cash->refresh();
        $revenue->refresh();
        $this->assertEquals(0, (int) $cash->getBalance()->getAmount());
        $this->assertEquals(0, (int) $revenue->getBalance()->getAmount());
    }

    #[Test]
    public function post_is_idempotent(): void
    {
        $je = JournalEntry::create([
            'date' => '2025-01-15',
            'memo' => 'Already posted',
            'is_posted' => true,
        ]);

        $result = $je->post();
        $this->assertSame($je, $result);
        $this->assertTrue($je->is_posted);
    }

    #[Test]
    public function unpost_is_idempotent(): void
    {
        $je = JournalEntry::create([
            'date' => '2025-01-15',
            'memo' => 'Already unposted',
            'is_posted' => false,
        ]);

        $result = $je->unpost();
        $this->assertSame($je, $result);
        $this->assertFalse($je->is_posted);
    }

    // -------------------------------------------------------
    // Exception path tests (H20)
    // -------------------------------------------------------

    #[Test]
    public function reverse_throws_logic_exception_on_unposted_entry(): void
    {
        $je = JournalEntry::create([
            'date' => '2025-01-15',
            'memo' => 'Draft',
            'is_posted' => false,
        ]);

        $this->expectException(\LogicException::class);
        $je->reverse();
    }

    #[Test]
    public function void_throws_logic_exception_on_unposted_entry(): void
    {
        $je = JournalEntry::create([
            'date' => '2025-01-15',
            'memo' => 'Draft',
            'is_posted' => false,
        ]);

        $this->expectException(\LogicException::class);
        $je->void();
    }

    // -------------------------------------------------------
    // Running balance chain tests (M22, M23, M24)
    // -------------------------------------------------------

    #[Test]
    public function running_balances_chain_across_sequential_posts(): void
    {
        $cash = Account::create(['name' => 'Cash', 'type' => AccountType::ASSET]);

        // First transaction: debit 5000
        $je1 = JournalEntry::create(['date' => '2025-01-15', 'is_posted' => false]);
        $je1->ledgerEntries()->create([
            'account_id' => $cash->id,
            'debit' => 5000,
            'credit' => 0,
            'currency' => 'USD',
            'post_date' => '2025-01-15',
            'is_posted' => false,
        ]);
        $je1->post();

        // Second transaction: debit 3000
        $je2 = JournalEntry::create(['date' => '2025-01-16', 'is_posted' => false]);
        $je2->ledgerEntries()->create([
            'account_id' => $cash->id,
            'debit' => 3000,
            'credit' => 0,
            'currency' => 'USD',
            'post_date' => '2025-01-16',
            'is_posted' => false,
        ]);
        $je2->post();

        $entries = \App\Accounting\Models\LedgerEntry::where('account_id', $cash->id)
            ->where('is_posted', true)
            ->orderBy('id')
            ->get();

        $this->assertCount(2, $entries);
        $this->assertEquals(5000, $entries[0]->running_balance);
        $this->assertEquals(8000, $entries[1]->running_balance);

        $cash->refresh();
        $this->assertEquals(8000, $cash->cached_balance);
    }

    #[Test]
    public function unposting_first_transaction_resequences_second_transactions_running_balance(): void
    {
        $cash = Account::create(['name' => 'Cash', 'type' => AccountType::ASSET]);

        // Post transaction 1 (debit 5000)
        $je1 = JournalEntry::create(['date' => '2025-01-15', 'is_posted' => false]);
        $entry1 = $je1->ledgerEntries()->create([
            'account_id' => $cash->id,
            'debit' => 5000,
            'credit' => 0,
            'currency' => 'USD',
            'post_date' => '2025-01-15',
            'is_posted' => false,
        ]);
        $je1->post();

        // Post transaction 2 (debit 3000 — running balance should be 8000)
        $je2 = JournalEntry::create(['date' => '2025-01-16', 'is_posted' => false]);
        $entry2 = $je2->ledgerEntries()->create([
            'account_id' => $cash->id,
            'debit' => 3000,
            'credit' => 0,
            'currency' => 'USD',
            'post_date' => '2025-01-16',
            'is_posted' => false,
        ]);
        $je2->post();

        // Unpost transaction 1 — transaction 2's running_balance must be resequenced to 3000
        $je1->unpost();

        $entry2->refresh();
        $this->assertEquals(3000, $entry2->running_balance);

        $cash->refresh();
        $this->assertEquals(3000, $cash->cached_balance);
    }

    #[Test]
    public function reversal_entry_running_balances_are_set_correctly(): void
    {
        $cash = Account::create(['name' => 'Cash', 'type' => AccountType::ASSET]);
        $revenue = Account::create(['name' => 'Revenue', 'type' => AccountType::REVENUE]);

        // Post original transaction: DR Cash 5000 / CR Revenue 5000
        $je = JournalEntry::create(['date' => '2025-01-15']);
        $je->ledgerEntries()->create([
            'account_id' => $cash->id,
            'debit' => 5000,
            'credit' => 0,
            'currency' => 'USD',
            'post_date' => '2025-01-15',
        ]);
        $je->ledgerEntries()->create([
            'account_id' => $revenue->id,
            'debit' => 0,
            'credit' => 5000,
            'currency' => 'USD',
            'post_date' => '2025-01-15',
        ]);

        $reversal = $je->reverse('Reversal test');

        // After reversal, net cash balance should be 0
        $cash->refresh();
        $revenue->refresh();
        $this->assertEquals(0, (int) $cash->getBalance()->getAmount());
        $this->assertEquals(0, (int) $revenue->getBalance()->getAmount());

        // Running balances on reversal entries should be 0 (net zero)
        $reversalCashEntry = $reversal->ledgerEntries()
            ->where('account_id', $cash->id)
            ->first();
        $this->assertEquals(0, $reversalCashEntry->running_balance);
    }
}
