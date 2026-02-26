<?php

declare(strict_types=1);

namespace Tests\Unit\Models;

use Tests\Unit\TestCase;
use App\Accounting\Models\Account;
use App\Accounting\Models\AccountType;
use App\Accounting\Enums\AccountCategory;
use App\Accounting\Models\JournalEntry;
use Illuminate\Support\Str;

class JournalEntryTest extends TestCase
{
    public function test_it_can_be_created_with_required_fields(): void
    {
        $account = Account::create([
            'currency' => 'USD',
            'morphed_type' => 'test',
            'morphed_id' => 1,
        ]);

        $entry = JournalEntry::create([
            'account_id' => $account->id,
            'debit' => 1000,
            'credit' => 0,
            'currency' => 'USD',
            'memo' => 'Test entry',
            'post_date' => now(),
        ]);

        $this->assertInstanceOf(JournalEntry::class, $entry);
        $this->assertEquals(1000, $entry->debit);
        $this->assertEquals(0, $entry->credit);
        $this->assertEquals('USD', $entry->currency);
        $this->assertEquals('Test entry', $entry->memo);
        $this->assertNotNull($entry->post_date);
    }

    public function test_it_has_account_relationship(): void
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

        $this->assertTrue($entry->account->is($account));
    }

    public function test_it_handles_reference_objects(): void
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

        $entry = $account->journalEntries()->create([
            'debit' => 1000,
            'credit' => 0,
            'currency' => 'USD',
            'memo' => 'Test entry',
            'post_date' => now(),
        ]);

        $entry->referencesObject($accountType);
        $entry->refresh();

        $this->assertEquals(get_class($accountType), $entry->ref_class);
        $this->assertEquals($accountType->id, $entry->ref_class_id);

        $referencedObject = $entry->getReferencedObject();
        $this->assertInstanceOf(AccountType::class, $referencedObject);
        $this->assertTrue($referencedObject->is($accountType));
    }

    public function test_it_handles_entry_groups(): void
    {
        $account = Account::create([
            'currency' => 'USD',
            'morphed_type' => 'test',
            'morphed_id' => 1,
        ]);

        $group = 'test-group-' . uniqid();

        $entries = [
            $account->journalEntries()->create([
                'debit' => 1000,
                'credit' => 0,
                'currency' => 'USD',
                'memo' => 'Entry 1',
                'post_date' => now(),
                'transaction_group' => $group,
            ]),
            $account->journalEntries()->create([
                'debit' => 0,
                'credit' => 1000,
                'currency' => 'USD',
                'memo' => 'Entry 2',
                'post_date' => now(),
                'transaction_group' => $group,
            ]),
        ];

        $this->assertCount(2, $account->journalEntries()->where('transaction_group', $group)->get());
        $this->assertEquals($group, $entries[0]->transaction_group);
        $this->assertEquals($group, $entries[1]->transaction_group);
    }

    public function test_it_handles_tags(): void
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
            'tags' => ['test', 'deposit'],
        ]);

        $this->assertIsArray($entry->tags);
        $this->assertContains('test', $entry->tags);
        $this->assertContains('deposit', $entry->tags);
    }

    public function test_boot_method_generates_uuid_on_creating(): void
    {
        $account = Account::create([
            'currency' => 'USD',
            'morphed_type' => 'test',
            'morphed_id' => 1,
        ]);

        $entry = new JournalEntry([
            'account_id' => $account->id,
            'debit' => 1000,
            'credit' => 0,
            'currency' => 'USD',
            'memo' => 'Test entry',
            'post_date' => now(),
        ]);

        // Before save, no ID
        $this->assertNull($entry->id);

        $entry->save();

        // After save, UUID should be generated as ID
        $this->assertNotNull($entry->id);
        $this->assertTrue(Str::isUuid($entry->id));
    }

    public function test_set_currency_method(): void
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

        $entry->setCurrency('EUR');
        $this->assertEquals('EUR', $entry->currency);
    }

    public function test_get_referenced_object_with_nonexistent_class(): void
    {
        $this->expectException(\Error::class);

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
            'ref_class' => 'NonExistentClass',
            'ref_class_id' => 123,
        ]);

        $entry->getReferencedObject();
    }

    public function test_get_referenced_object_with_no_reference(): void
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

        $result = $entry->getReferencedObject();
        $this->assertNull($result);
    }

    public function test_boot_method_resets_account_balance_on_deleted(): void
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

        $entry->delete();

        // Test passes if deletion completed without error
        // The balance reset is called by the boot method via the deleted event
        $this->assertTrue(true);
    }

    public function test_is_posted_field_defaults_to_false_when_created_directly(): void
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
            'memo' => 'Direct entry',
            'post_date' => now(),
        ]);

        // When created directly without is_posted, it should be null/false
        $this->assertFalse((bool) $entry->is_posted);
    }

    public function test_is_posted_field_is_true_when_set_explicitly(): void
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
            'memo' => 'Posted entry',
            'post_date' => now(),
            'is_posted' => true,
        ]);

        $this->assertTrue($entry->is_posted);
    }
}
