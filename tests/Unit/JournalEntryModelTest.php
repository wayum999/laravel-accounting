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
        $revenue = Account::create(['name' => 'Revenue', 'type' => AccountType::INCOME]);

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
        $revenue = Account::create(['name' => 'Revenue', 'type' => AccountType::INCOME]);

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
        $revenue = Account::create(['name' => 'Revenue', 'type' => AccountType::INCOME]);

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
        $revenue = Account::create(['name' => 'Revenue', 'type' => AccountType::INCOME]);

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
}
