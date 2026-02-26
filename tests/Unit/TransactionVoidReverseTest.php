<?php

declare(strict_types=1);

namespace Tests\Unit;

use Tests\TestCase;
use App\Accounting\Models\Account;
use App\Accounting\Models\AccountType;
use App\Accounting\Models\JournalEntry;
use App\Accounting\Transaction;
use App\Accounting\Enums\AccountCategory;
use App\Accounting\Exceptions\TransactionAlreadyReversedException;
use Money\Money;
use Money\Currency;
use Carbon\Carbon;

class TransactionVoidReverseTest extends TestCase
{
    private Account $cashAccount;
    private Account $revenueAccount;

    protected function setUp(): void
    {
        parent::setUp();

        $assetType = AccountType::create([
            'name' => 'Assets',
            'type' => AccountCategory::ASSET,
            'code' => 'ASSET',
        ]);

        $incomeType = AccountType::create([
            'name' => 'Income',
            'type' => AccountCategory::INCOME,
            'code' => 'INCOME',
        ]);

        $this->cashAccount = Account::create([
            'account_type_id' => $assetType->id,
            'name' => 'Cash',
            'number' => '1000',
            'currency' => 'USD',
            'morphed_type' => 'system',
            'morphed_id' => 0,
        ]);

        $this->revenueAccount = Account::create([
            'account_type_id' => $incomeType->id,
            'name' => 'Revenue',
            'number' => '4000',
            'currency' => 'USD',
            'morphed_type' => 'system',
            'morphed_id' => 0,
        ]);
    }

    public function test_reverse_single_entry_creates_opposite_entry(): void
    {
        $entry = $this->cashAccount->debit(
            new Money(10000, new Currency('USD')),
            'Payment received',
            Carbon::parse('2025-01-15')
        );

        $reversal = $entry->reverse('Correcting entry');

        $this->assertNotNull($reversal);
        $this->assertEquals(0, $reversal->debit);
        $this->assertEquals(10000, $reversal->credit);
        $this->assertEquals('Correcting entry', $reversal->memo);
        $this->assertEquals($entry->id, $reversal->reversal_of);
    }

    public function test_reverse_sets_cross_references(): void
    {
        $entry = $this->cashAccount->debit(
            new Money(5000, new Currency('USD')),
            'Original entry'
        );

        $reversal = $entry->reverse();

        $entry->refresh();
        $this->assertTrue($entry->is_reversed);
        $this->assertEquals($reversal->id, $entry->reversed_by);
        $this->assertEquals($entry->id, $reversal->reversal_of);
    }

    public function test_reverse_updates_account_balance(): void
    {
        $entry = $this->cashAccount->debit(
            new Money(10000, new Currency('USD')),
            'Payment'
        );

        $this->assertEquals(10000, (int) $this->cashAccount->getBalance()->getAmount());

        $entry->reverse();

        $this->assertEquals(0, (int) $this->cashAccount->getBalance()->getAmount());
    }

    public function test_void_uses_original_post_date_and_void_memo(): void
    {
        $postDate = Carbon::parse('2025-03-15');
        $entry = $this->cashAccount->debit(
            new Money(7500, new Currency('USD')),
            'Invoice payment',
            $postDate
        );

        $voidEntry = $entry->void();

        $this->assertEquals('VOID: Invoice payment', $voidEntry->memo);
        $this->assertEquals(
            $postDate->toDateString(),
            $voidEntry->post_date->toDateString()
        );
    }

    public function test_cannot_reverse_already_reversed_entry(): void
    {
        $entry = $this->cashAccount->debit(
            new Money(5000, new Currency('USD')),
            'Test entry'
        );

        $entry->reverse();

        $this->expectException(TransactionAlreadyReversedException::class);
        $entry->refresh();
        $entry->reverse();
    }

    public function test_reverse_transaction_group(): void
    {
        $transaction = Transaction::newDoubleEntryTransactionGroup();
        $transaction->addTransaction(
            $this->cashAccount,
            'debit',
            new Money(25000, new Currency('USD')),
            'Sale payment'
        );
        $transaction->addTransaction(
            $this->revenueAccount,
            'credit',
            new Money(25000, new Currency('USD')),
            'Sale revenue'
        );
        $groupUuid = $transaction->commit();

        $this->assertEquals(25000, (int) $this->cashAccount->getBalance()->getAmount());
        $this->assertEquals(25000, (int) $this->revenueAccount->getBalance()->getAmount());

        $reversalGroupUuid = Transaction::reverseGroup($groupUuid);

        $this->assertNotEquals($groupUuid, $reversalGroupUuid);
        $this->assertEquals(0, (int) $this->cashAccount->getBalance()->getAmount());
        $this->assertEquals(0, (int) $this->revenueAccount->getBalance()->getAmount());

        // Verify original entries are marked as reversed
        $originalEntries = JournalEntry::where('transaction_group', $groupUuid)
            ->whereNull('reversal_of')
            ->get();
        foreach ($originalEntries as $entry) {
            $this->assertTrue($entry->is_reversed);
            $this->assertNotNull($entry->reversed_by);
        }
    }

    public function test_void_transaction_group(): void
    {
        $postDate = Carbon::parse('2025-06-01');
        $transaction = Transaction::newDoubleEntryTransactionGroup();
        $transaction->addTransaction(
            $this->cashAccount,
            'debit',
            new Money(15000, new Currency('USD')),
            'Service payment',
            null,
            $postDate
        );
        $transaction->addTransaction(
            $this->revenueAccount,
            'credit',
            new Money(15000, new Currency('USD')),
            'Service revenue',
            null,
            $postDate
        );
        $groupUuid = $transaction->commit();

        $voidGroupUuid = Transaction::voidGroup($groupUuid);

        // Check void entries have original post date
        $voidEntries = JournalEntry::where('transaction_group', $voidGroupUuid)->get();
        foreach ($voidEntries as $entry) {
            $this->assertEquals(
                $postDate->toDateString(),
                $entry->post_date->toDateString()
            );
        }
    }

    public function test_cannot_reverse_already_reversed_group(): void
    {
        $transaction = Transaction::newDoubleEntryTransactionGroup();
        $transaction->addTransaction(
            $this->cashAccount,
            'debit',
            new Money(10000, new Currency('USD')),
            'Test'
        );
        $transaction->addTransaction(
            $this->revenueAccount,
            'credit',
            new Money(10000, new Currency('USD')),
            'Test'
        );
        $groupUuid = $transaction->commit();

        Transaction::reverseGroup($groupUuid);

        $this->expectException(TransactionAlreadyReversedException::class);
        Transaction::reverseGroup($groupUuid);
    }

    public function test_reversal_preserves_currency(): void
    {
        $entry = $this->cashAccount->debit(
            new Money(10000, new Currency('USD')),
            'USD entry'
        );

        $reversal = $entry->reverse();
        $this->assertEquals('USD', $reversal->currency);
    }

    public function test_reversal_entry_relationships(): void
    {
        $entry = $this->cashAccount->debit(
            new Money(5000, new Currency('USD')),
            'Original'
        );

        $reversal = $entry->reverse();

        $entry->refresh();
        $this->assertEquals($reversal->id, $entry->reversedByEntry->id);
        $this->assertEquals($entry->id, $reversal->reversalOfEntry->id);
    }
}
