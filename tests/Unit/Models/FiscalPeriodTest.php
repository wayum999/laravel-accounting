<?php

declare(strict_types=1);

namespace Tests\Unit\Models;

use Tests\Unit\TestCase;
use App\Accounting\Models\Account;
use App\Accounting\Models\AccountType;
use App\Accounting\Models\FiscalPeriod;
use App\Accounting\Models\JournalEntry;
use App\Accounting\Enums\AccountCategory;
use App\Accounting\Exceptions\FiscalPeriodOverlapException;
use App\Accounting\Exceptions\PeriodClosedException;
use Carbon\Carbon;
use Money\Money;
use Money\Currency;

class FiscalPeriodTest extends TestCase
{
    private Account $cashAccount;
    private Account $revenueAccount;
    private Account $expenseAccount;
    private Account $retainedEarningsAccount;

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

        $expenseType = AccountType::create([
            'name' => 'Expenses',
            'type' => AccountCategory::EXPENSE,
            'code' => 'EXPENSE',
        ]);

        $equityType = AccountType::create([
            'name' => 'Equity',
            'type' => AccountCategory::EQUITY,
            'code' => 'EQUITY',
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

        $this->expenseAccount = Account::create([
            'account_type_id' => $expenseType->id,
            'name' => 'Rent Expense',
            'number' => '5000',
            'currency' => 'USD',
            'morphed_type' => 'system',
            'morphed_id' => 0,
        ]);

        $this->retainedEarningsAccount = Account::create([
            'account_type_id' => $equityType->id,
            'name' => 'Retained Earnings',
            'number' => '3100',
            'currency' => 'USD',
            'morphed_type' => 'system',
            'morphed_id' => 0,
        ]);
    }

    public function test_can_create_fiscal_period(): void
    {
        $period = FiscalPeriod::create([
            'name' => 'January 2025',
            'start_date' => Carbon::parse('2025-01-01'),
            'end_date' => Carbon::parse('2025-01-31'),
        ]);

        $this->assertDatabaseHas('accounting_fiscal_periods', [
            'name' => 'January 2025',
            'status' => 'open',
        ]);
    }

    public function test_cannot_create_overlapping_periods(): void
    {
        FiscalPeriod::create([
            'name' => 'January 2025',
            'start_date' => Carbon::parse('2025-01-01'),
            'end_date' => Carbon::parse('2025-01-31'),
        ]);

        $this->expectException(FiscalPeriodOverlapException::class);

        FiscalPeriod::create([
            'name' => 'Mid January 2025',
            'start_date' => Carbon::parse('2025-01-15'),
            'end_date' => Carbon::parse('2025-02-15'),
        ]);
    }

    public function test_adjacent_periods_do_not_overlap(): void
    {
        FiscalPeriod::create([
            'name' => 'January 2025',
            'start_date' => Carbon::parse('2025-01-01'),
            'end_date' => Carbon::parse('2025-01-31'),
        ]);

        $feb = FiscalPeriod::create([
            'name' => 'February 2025',
            'start_date' => Carbon::parse('2025-02-01'),
            'end_date' => Carbon::parse('2025-02-28'),
        ]);

        $this->assertNotNull($feb->id);
    }

    public function test_close_period_generates_closing_entries(): void
    {
        $period = FiscalPeriod::create([
            'name' => 'January 2025',
            'start_date' => Carbon::parse('2025-01-01'),
            'end_date' => Carbon::parse('2025-01-31'),
        ]);

        // Create revenue: credit 50000 cents ($500)
        $this->revenueAccount->credit(
            new Money(50000, new Currency('USD')),
            'Service revenue',
            Carbon::parse('2025-01-15')
        );

        // Create expense: debit 20000 cents ($200)
        $this->expenseAccount->debit(
            new Money(20000, new Currency('USD')),
            'Rent payment',
            Carbon::parse('2025-01-20')
        );

        $period->close($this->retainedEarningsAccount, 'admin');

        $period->refresh();
        $this->assertEquals('closed', $period->status);
        $this->assertNotNull($period->closed_at);
        $this->assertEquals('admin', $period->closed_by);
        $this->assertNotNull($period->closing_transaction_group);

        // Revenue should be zeroed out
        $this->assertEquals(0, (int) $this->revenueAccount->getBalance()->getAmount());
        // Expense should be zeroed out
        $this->assertEquals(0, (int) $this->expenseAccount->getBalance()->getAmount());
        // Retained Earnings should have net income (500 - 200 = 300 = 30000 cents)
        $this->assertEquals(30000, (int) $this->retainedEarningsAccount->getBalance()->getAmount());
    }

    public function test_closed_period_blocks_posting(): void
    {
        $period = FiscalPeriod::create([
            'name' => 'January 2025',
            'start_date' => Carbon::parse('2025-01-01'),
            'end_date' => Carbon::parse('2025-01-31'),
        ]);

        $period->close($this->retainedEarningsAccount);

        $this->expectException(PeriodClosedException::class);
        FiscalPeriod::validateDateNotClosed(Carbon::parse('2025-01-15'));
    }

    public function test_open_period_allows_posting(): void
    {
        FiscalPeriod::create([
            'name' => 'January 2025',
            'start_date' => Carbon::parse('2025-01-01'),
            'end_date' => Carbon::parse('2025-01-31'),
        ]);

        // Should not throw
        FiscalPeriod::validateDateNotClosed(Carbon::parse('2025-01-15'));
        $this->assertTrue(true);
    }

    public function test_reopen_period_reverses_closing_entries(): void
    {
        $period = FiscalPeriod::create([
            'name' => 'January 2025',
            'start_date' => Carbon::parse('2025-01-01'),
            'end_date' => Carbon::parse('2025-01-31'),
        ]);

        $this->revenueAccount->credit(
            new Money(30000, new Currency('USD')),
            'Revenue',
            Carbon::parse('2025-01-10')
        );

        $period->close($this->retainedEarningsAccount);

        $this->assertEquals(0, (int) $this->revenueAccount->getBalance()->getAmount());

        $period->reopen();

        $period->refresh();
        $this->assertEquals('open', $period->status);
        $this->assertNull($period->closed_at);
        // Revenue balance should be restored
        $this->assertEquals(30000, (int) $this->revenueAccount->getBalance()->getAmount());
    }

    public function test_generate_monthly_periods(): void
    {
        $periods = FiscalPeriod::generateMonthly(
            Carbon::parse('2025-01-01'),
            Carbon::parse('2025-03-31')
        );

        $this->assertCount(3, $periods);
        $this->assertEquals('January 2025', $periods[0]->name);
        $this->assertEquals('February 2025', $periods[1]->name);
        $this->assertEquals('March 2025', $periods[2]->name);
    }

    public function test_period_scopes(): void
    {
        $jan = FiscalPeriod::create([
            'name' => 'January 2025',
            'start_date' => Carbon::parse('2025-01-01'),
            'end_date' => Carbon::parse('2025-01-31'),
        ]);

        $feb = FiscalPeriod::create([
            'name' => 'February 2025',
            'start_date' => Carbon::parse('2025-02-01'),
            'end_date' => Carbon::parse('2025-02-28'),
        ]);

        $jan->close($this->retainedEarningsAccount);

        $this->assertEquals(1, FiscalPeriod::open()->count());
        $this->assertEquals(1, FiscalPeriod::closed()->count());
    }

    public function test_close_period_with_no_income_expense(): void
    {
        $period = FiscalPeriod::create([
            'name' => 'January 2025',
            'start_date' => Carbon::parse('2025-01-01'),
            'end_date' => Carbon::parse('2025-01-31'),
        ]);

        // Close with no income/expense entries - should work fine
        $period->close($this->retainedEarningsAccount);

        $period->refresh();
        $this->assertEquals('closed', $period->status);
        $this->assertNull($period->closing_transaction_group);
    }
}
