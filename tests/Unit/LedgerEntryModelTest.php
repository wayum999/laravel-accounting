<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Accounting\Enums\AccountType;
use App\Accounting\Exceptions\ImmutableEntryException;
use App\Accounting\Models\Account;
use App\Accounting\Models\JournalEntry;
use App\Accounting\Models\LedgerEntry;
use Illuminate\Database\Eloquent\Model;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class LedgerEntryModelTest extends TestCase
{
    #[Test]
    public function it_belongs_to_an_account(): void
    {
        $account = Account::create(['name' => 'Cash', 'type' => AccountType::ASSET]);

        $entry = $account->ledgerEntries()->create([
            'debit' => 5000,
            'credit' => 0,
            'currency' => 'USD',
            'post_date' => now(),
        ]);

        $this->assertEquals($account->id, $entry->account->id);
    }

    #[Test]
    public function it_belongs_to_a_journal_entry(): void
    {
        $account = Account::create(['name' => 'Cash', 'type' => AccountType::ASSET]);

        $je = JournalEntry::create([
            'date' => '2025-01-15',
            'memo' => 'Test',
        ]);

        $entry = $je->ledgerEntries()->create([
            'account_id' => $account->id,
            'debit' => 1000,
            'credit' => 0,
            'currency' => 'USD',
            'post_date' => now(),
        ]);

        $this->assertEquals($je->id, $entry->journalEntry->id);
    }

    #[Test]
    public function it_can_reference_a_model_via_ledgerable(): void
    {
        $account = Account::create(['name' => 'Cash', 'type' => AccountType::ASSET]);

        // Use another Account as a "referenced model" for testing
        $referencedModel = Account::create(['name' => 'Reference', 'type' => AccountType::ASSET]);

        // Pass ledgerable data at creation time (entries are immutable)
        $entry = $account->ledgerEntries()->create([
            'debit' => 5000,
            'credit' => 0,
            'currency' => 'USD',
            'post_date' => now(),
            'ledgerable_type' => $referencedModel->getMorphClass(),
            'ledgerable_id' => $referencedModel->id,
        ]);

        $entry->refresh();

        $this->assertEquals($referencedModel->getMorphClass(), $entry->ledgerable_type);
        $this->assertEquals($referencedModel->id, $entry->ledgerable_id);

        $resolved = $entry->getReferencedModel();
        $this->assertInstanceOf(Account::class, $resolved);
        $this->assertEquals($referencedModel->id, $resolved->id);
    }

    #[Test]
    public function it_returns_null_when_no_reference_set(): void
    {
        $account = Account::create(['name' => 'Cash', 'type' => AccountType::ASSET]);

        $entry = $account->ledgerEntries()->create([
            'debit' => 5000,
            'credit' => 0,
            'currency' => 'USD',
            'post_date' => now(),
        ]);

        $this->assertNull($entry->getReferencedModel());
    }

    #[Test]
    public function creating_entry_recalculates_account_balance(): void
    {
        // Entries created via Account::debit()/credit() trigger recalculation.
        // Direct ledgerEntries()->create() does NOT (running_balance and cached_balance
        // are managed by resequenceRunningBalances() / recalculateBalance() which are
        // called from Account::debit(), Account::credit(), and TransactionBuilder).
        $account = Account::create(['name' => 'Cash', 'type' => AccountType::ASSET]);
        $this->assertEquals(0, $account->cached_balance);

        $account->debit(5000, 'Deposit');

        $account->refresh();
        $this->assertEquals(5000, $account->cached_balance);
    }

    #[Test]
    public function deleting_entry_throws_immutable_exception(): void
    {
        $account = Account::create(['name' => 'Cash', 'type' => AccountType::ASSET]);

        $entry = $account->ledgerEntries()->create([
            'debit' => 5000,
            'credit' => 0,
            'currency' => 'USD',
            'post_date' => now(),
        ]);

        $this->expectException(ImmutableEntryException::class);
        $entry->delete();
    }

    #[Test]
    public function updating_entry_throws_immutable_exception(): void
    {
        $account = Account::create(['name' => 'Cash', 'type' => AccountType::ASSET]);

        $entry = $account->ledgerEntries()->create([
            'debit' => 5000,
            'credit' => 0,
            'currency' => 'USD',
            'post_date' => now(),
        ]);

        $this->expectException(ImmutableEntryException::class);
        $entry->memo = 'Changed';
        $entry->save();
    }

    #[Test]
    public function it_computes_running_balance_on_creation_debit_normal(): void
    {
        $account = Account::create(['name' => 'Cash', 'type' => AccountType::ASSET]);

        // Debit-normal: running_balance = cumulative (debit - credit).
        // running_balance is 0 at creation and set by resequenceRunningBalances(),
        // which Account::debit()/credit() call automatically.
        $entry1 = $account->debit(5000);
        $entry1->refresh();
        $this->assertEquals(5000, $entry1->running_balance);

        $entry2 = $account->credit(2000);
        $entry2->refresh();
        $this->assertEquals(3000, $entry2->running_balance);
    }

    #[Test]
    public function it_computes_running_balance_on_creation_credit_normal(): void
    {
        $account = Account::create(['name' => 'Revenue', 'type' => AccountType::INCOME]);

        // Credit-normal: running_balance = cumulative (credit - debit).
        // running_balance is 0 at creation and set by resequenceRunningBalances(),
        // which Account::debit()/credit() call automatically.
        $entry1 = $account->credit(8000);
        $entry1->refresh();
        $this->assertEquals(8000, $entry1->running_balance);

        $entry2 = $account->debit(3000);
        $entry2->refresh();
        $this->assertEquals(5000, $entry2->running_balance);
    }

    #[Test]
    public function running_balance_chains_correctly_for_same_account(): void
    {
        $account = Account::create(['name' => 'Cash', 'type' => AccountType::ASSET]);

        $entry1 = $account->debit(10000);
        $entry1->refresh();
        $this->assertEquals(10000, $entry1->running_balance);

        $entry2 = $account->debit(5000);
        $entry2->refresh();
        $this->assertEquals(15000, $entry2->running_balance);

        $entry3 = $account->credit(3000);
        $entry3->refresh();
        $this->assertEquals(12000, $entry3->running_balance);
    }

    #[Test]
    public function unposted_entry_does_not_affect_running_balance(): void
    {
        $account = Account::create(['name' => 'Cash', 'type' => AccountType::ASSET]);

        $posted = $account->debit(5000, 'First deposit');
        $posted->refresh();
        $this->assertEquals(5000, $posted->running_balance);

        // Draft entry: created directly with is_posted=false; running_balance stays 0
        $draft = $account->ledgerEntries()->create([
            'debit' => 3000,
            'credit' => 0,
            'currency' => 'USD',
            'post_date' => now(),
            'is_posted' => false,
        ]);
        $this->assertEquals(0, $draft->running_balance);

        // Next posted entry should chain from the first posted entry, not the draft
        $posted2 = $account->debit(2000, 'Second deposit');
        $posted2->refresh();
        $this->assertEquals(7000, $posted2->running_balance);
    }

    #[Test]
    public function unposted_entry_does_not_affect_account_balance(): void
    {
        $account = Account::create(['name' => 'Cash', 'type' => AccountType::ASSET]);

        $account->debit(5000, 'Initial deposit');

        $account->refresh();
        $this->assertEquals(5000, $account->cached_balance);

        // Draft entry should not change the balance
        $account->ledgerEntries()->create([
            'debit' => 10000,
            'credit' => 0,
            'currency' => 'USD',
            'post_date' => now(),
            'is_posted' => false,
        ]);

        $account->refresh();
        $this->assertEquals(5000, $account->cached_balance);
    }

    #[Test]
    public function is_posted_can_be_changed_on_existing_entry(): void
    {
        $account = Account::create(['name' => 'Cash', 'type' => AccountType::ASSET]);

        $entry = $account->ledgerEntries()->create([
            'debit' => 5000,
            'credit' => 0,
            'currency' => 'USD',
            'post_date' => now(),
            'is_posted' => false,
        ]);

        // Changing is_posted should be allowed (not throw ImmutableEntryException)
        $entry->is_posted = true;
        $entry->save();

        $entry->refresh();
        $this->assertTrue($entry->is_posted);
    }

    #[Test]
    public function it_casts_debit_and_credit_to_integer(): void
    {
        $account = Account::create(['name' => 'Cash', 'type' => AccountType::ASSET]);

        $entry = $account->ledgerEntries()->create([
            'debit' => 5000,
            'credit' => 0,
            'currency' => 'USD',
            'post_date' => now(),
        ]);

        $this->assertIsInt($entry->debit);
        $this->assertIsInt($entry->credit);
    }

    #[Test]
    public function it_casts_tags_to_array(): void
    {
        $account = Account::create(['name' => 'Cash', 'type' => AccountType::ASSET]);

        $entry = $account->ledgerEntries()->create([
            'debit' => 5000,
            'credit' => 0,
            'currency' => 'USD',
            'post_date' => now(),
            'tags' => ['category' => 'sales', 'department' => 'retail'],
        ]);

        $entry->refresh();
        $this->assertIsArray($entry->tags);
        $this->assertEquals('sales', $entry->tags['category']);
    }
}
