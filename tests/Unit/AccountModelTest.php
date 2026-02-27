<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Accounting\Enums\AccountType;
use App\Accounting\Models\Account;
use Carbon\Carbon;
use Money\Money;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AccountModelTest extends TestCase
{
    #[Test]
    public function it_creates_an_account_with_defaults(): void
    {
        $account = Account::create([
            'name' => 'Cash',
            'code' => '1000',
            'type' => AccountType::ASSET,
        ]);

        $this->assertDatabaseHas('accounting_accounts', [
            'name' => 'Cash',
            'code' => '1000',
            'type' => 'asset',
            'currency' => 'USD',
            'cached_balance' => 0,
            'is_active' => true,
        ]);
    }

    #[Test]
    public function it_casts_type_to_enum(): void
    {
        $account = Account::create([
            'name' => 'Cash',
            'type' => AccountType::ASSET,
        ]);

        $this->assertInstanceOf(AccountType::class, $account->type);
        $this->assertEquals(AccountType::ASSET, $account->type);
    }

    #[Test]
    public function asset_is_debit_normal(): void
    {
        $account = Account::create(['name' => 'Cash', 'type' => AccountType::ASSET]);
        $this->assertTrue($account->isDebitNormal());
        $this->assertFalse($account->isCreditNormal());
    }

    #[Test]
    public function liability_is_credit_normal(): void
    {
        $account = Account::create(['name' => 'AP', 'type' => AccountType::LIABILITY]);
        $this->assertFalse($account->isDebitNormal());
        $this->assertTrue($account->isCreditNormal());
    }

    #[Test]
    public function it_computes_balance_for_debit_normal_account(): void
    {
        $account = Account::create(['name' => 'Cash', 'type' => AccountType::ASSET]);

        $account->debit(5000, 'Deposit');
        $account->credit(2000, 'Withdrawal');

        $balance = $account->getBalance();
        $this->assertEquals(3000, (int) $balance->getAmount());
    }

    #[Test]
    public function it_computes_balance_for_credit_normal_account(): void
    {
        $account = Account::create(['name' => 'Revenue', 'type' => AccountType::INCOME]);

        $account->credit(5000, 'Sale');
        $account->credit(3000, 'Sale 2');
        $account->debit(1000, 'Refund');

        $balance = $account->getBalance();
        $this->assertEquals(7000, (int) $balance->getAmount());
    }

    #[Test]
    public function it_computes_balance_in_dollars(): void
    {
        $account = Account::create(['name' => 'Cash', 'type' => AccountType::ASSET]);

        $account->debit(15075);

        $this->assertEquals(150.75, $account->getBalanceInDollars());
        $this->assertEquals(150.75, $account->getCurrentBalanceInDollars());
    }

    #[Test]
    public function it_computes_balance_on_a_specific_date(): void
    {
        $account = Account::create(['name' => 'Cash', 'type' => AccountType::ASSET]);

        $account->debit(5000, 'Day 1 deposit', Carbon::parse('2025-01-01'));
        $account->debit(3000, 'Day 2 deposit', Carbon::parse('2025-01-02'));
        $account->debit(2000, 'Day 3 deposit', Carbon::parse('2025-01-03'));

        $balanceDay1 = $account->getBalanceOn(Carbon::parse('2025-01-01'));
        $this->assertEquals(5000, (int) $balanceDay1->getAmount());

        $balanceDay2 = $account->getBalanceOn(Carbon::parse('2025-01-02'));
        $this->assertEquals(8000, (int) $balanceDay2->getAmount());

        $balanceDay3 = $account->getBalanceOn(Carbon::parse('2025-01-03'));
        $this->assertEquals(10000, (int) $balanceDay3->getAmount());
    }

    #[Test]
    public function it_posts_debit_entries(): void
    {
        $account = Account::create(['name' => 'Cash', 'type' => AccountType::ASSET]);

        $entry = $account->debit(5000, 'Test debit');

        $this->assertEquals(5000, $entry->debit);
        $this->assertEquals(0, $entry->credit);
        $this->assertEquals('Test debit', $entry->memo);
    }

    #[Test]
    public function it_posts_credit_entries(): void
    {
        $account = Account::create(['name' => 'Revenue', 'type' => AccountType::INCOME]);

        $entry = $account->credit(3000, 'Test credit');

        $this->assertEquals(0, $entry->debit);
        $this->assertEquals(3000, $entry->credit);
    }

    #[Test]
    public function it_posts_dollar_amounts(): void
    {
        $account = Account::create(['name' => 'Cash', 'type' => AccountType::ASSET]);

        $entry = $account->debitDollars(50.75, 'Dollar debit');
        $this->assertEquals(5075, $entry->debit);

        $entry = $account->creditDollars(25.50, 'Dollar credit');
        $this->assertEquals(2550, $entry->credit);
    }

    #[Test]
    public function increase_adds_debit_for_asset(): void
    {
        $account = Account::create(['name' => 'Cash', 'type' => AccountType::ASSET]);

        $entry = $account->increase(5000, 'Cash in');
        $this->assertEquals(5000, $entry->debit);
        $this->assertEquals(0, $entry->credit);
    }

    #[Test]
    public function increase_adds_credit_for_liability(): void
    {
        $account = Account::create(['name' => 'Loan', 'type' => AccountType::LIABILITY]);

        $entry = $account->increase(5000, 'Borrowed');
        $this->assertEquals(0, $entry->debit);
        $this->assertEquals(5000, $entry->credit);
    }

    #[Test]
    public function decrease_adds_credit_for_asset(): void
    {
        $account = Account::create(['name' => 'Cash', 'type' => AccountType::ASSET]);

        $entry = $account->decrease(3000, 'Cash out');
        $this->assertEquals(0, $entry->debit);
        $this->assertEquals(3000, $entry->credit);
    }

    #[Test]
    public function decrease_adds_debit_for_liability(): void
    {
        $account = Account::create(['name' => 'Loan', 'type' => AccountType::LIABILITY]);

        $entry = $account->decrease(2000, 'Payment');
        $this->assertEquals(2000, $entry->debit);
        $this->assertEquals(0, $entry->credit);
    }

    #[Test]
    public function it_tracks_daily_activity(): void
    {
        Carbon::setTestNow(Carbon::parse('2025-06-15 12:00:00'));

        $account = Account::create(['name' => 'Cash', 'type' => AccountType::ASSET]);

        $account->debit(5000, 'Deposit');
        $account->credit(2000, 'Payment');

        $this->assertEquals(50.00, $account->getDollarsDebitedToday());
        $this->assertEquals(20.00, $account->getDollarsCreditedToday());

        Carbon::setTestNow();
    }

    #[Test]
    public function it_recalculates_cached_balance(): void
    {
        $account = Account::create(['name' => 'Cash', 'type' => AccountType::ASSET]);

        $account->debit(5000);
        $account->debit(3000);
        $account->credit(2000);

        $balance = $account->recalculateBalance();
        $this->assertEquals(6000, (int) $balance->getAmount());

        $account->refresh();
        $this->assertEquals(6000, $account->cached_balance);
    }

    #[Test]
    public function balance_attribute_returns_money_object(): void
    {
        $account = Account::create([
            'name' => 'Cash',
            'type' => AccountType::ASSET,
            'currency' => 'USD',
        ]);

        $balance = $account->balance;
        $this->assertInstanceOf(Money::class, $balance);
        $this->assertEquals('USD', $balance->getCurrency()->getCode());
    }

    #[Test]
    public function it_supports_money_object_amounts(): void
    {
        $account = Account::create(['name' => 'Cash', 'type' => AccountType::ASSET]);

        $money = Money::USD(7500);
        $entry = $account->debit($money, 'Money object deposit');

        $this->assertEquals(7500, $entry->debit);
        $this->assertEquals('USD', $entry->currency);
    }

    #[Test]
    public function it_supports_parent_child_accounts(): void
    {
        $parent = Account::create(['name' => 'Bank Accounts', 'type' => AccountType::ASSET]);
        $child = Account::create([
            'name' => 'Checking',
            'type' => AccountType::ASSET,
            'parent_id' => $parent->id,
        ]);

        $this->assertEquals($parent->id, $child->parent->id);
        $this->assertCount(1, $parent->children);
        $this->assertEquals('Checking', $parent->children->first()->name);
    }

    #[Test]
    public function it_soft_deletes(): void
    {
        $account = Account::create(['name' => 'Old Account', 'type' => AccountType::ASSET]);
        $id = $account->id;

        $account->delete();

        $this->assertSoftDeleted('accounting_accounts', ['id' => $id]);
        $this->assertNull(Account::find($id));
        $this->assertNotNull(Account::withTrashed()->find($id));
    }
}
