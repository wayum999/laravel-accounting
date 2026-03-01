<?php

declare(strict_types=1);

namespace Tests\Functional;

use App\Accounting\Enums\AccountSubType;
use App\Accounting\Enums\AccountType;
use App\Accounting\Models\Account;
use App\Accounting\Services\ChartOfAccountsSeeder;
use App\Accounting\Services\Reports\AgingReport;
use App\Accounting\Services\Reports\BalanceSheet;
use App\Accounting\Services\Reports\CashFlowStatement;
use App\Accounting\Services\Reports\IncomeStatement;
use App\Accounting\Services\Reports\TrialBalance;
use App\Accounting\Services\TransactionBuilder;
use Carbon\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ReportsTest extends TestCase
{
    private Account $cash;
    private Account $ar;
    private Account $ap;
    private Account $equity;
    private Account $revenue;
    private Account $expense;

    protected function tearDown(): void
    {
        // Reset any frozen time so Carbon mock leaks don't affect subsequent tests
        // when an assertion fails before the inline Carbon::setTestNow() cleanup call.
        Carbon::setTestNow(null);
        parent::tearDown();
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->cash = Account::create(['name' => 'Cash', 'code' => '1000', 'type' => AccountType::ASSET, 'sub_type' => AccountSubType::BANK]);
        $this->ar = Account::create(['name' => 'Accounts Receivable', 'code' => '1100', 'type' => AccountType::ASSET, 'sub_type' => AccountSubType::ACCOUNTS_RECEIVABLE]);
        $this->ap = Account::create(['name' => 'Accounts Payable', 'code' => '2000', 'type' => AccountType::LIABILITY, 'sub_type' => AccountSubType::ACCOUNTS_PAYABLE]);
        $this->equity = Account::create(['name' => "Owner's Equity", 'code' => '3000', 'type' => AccountType::EQUITY, 'sub_type' => AccountSubType::OWNERS_EQUITY]);
        $this->revenue = Account::create(['name' => 'Revenue', 'code' => '4000', 'type' => AccountType::INCOME, 'sub_type' => AccountSubType::REVENUE]);
        $this->expense = Account::create(['name' => 'Rent', 'code' => '5000', 'type' => AccountType::EXPENSE, 'sub_type' => AccountSubType::OPERATING_EXPENSE]);
    }

    // -------------------------------------------------------
    // Trial Balance
    // -------------------------------------------------------

    #[Test]
    public function trial_balance_is_balanced_after_transactions(): void
    {
        // Owner invests $10,000
        TransactionBuilder::create()
            ->date('2025-01-01')
            ->debit($this->cash, 1000000)
            ->credit($this->equity, 1000000)
            ->commit();

        // Pay rent $2,000
        TransactionBuilder::create()
            ->date('2025-01-15')
            ->debit($this->expense, 200000)
            ->credit($this->cash, 200000)
            ->commit();

        // Earn revenue $5,000
        TransactionBuilder::create()
            ->date('2025-01-20')
            ->debit($this->cash, 500000)
            ->credit($this->revenue, 500000)
            ->commit();

        $report = TrialBalance::generate(Carbon::parse('2025-01-31'));

        $this->assertTrue($report['is_balanced']);
        $this->assertEquals($report['total_debits'], $report['total_credits']);
        $this->assertNotEmpty($report['accounts']);
    }

    #[Test]
    public function trial_balance_excludes_zero_accounts_by_default(): void
    {
        TransactionBuilder::create()
            ->date('2025-01-01')
            ->debit($this->cash, 5000)
            ->credit($this->revenue, 5000)
            ->commit();

        $report = TrialBalance::generate();

        // Only cash and revenue should appear (not AR, AP, equity, expense)
        $this->assertCount(2, $report['accounts']);
    }

    #[Test]
    public function trial_balance_includes_zero_accounts_when_requested(): void
    {
        TransactionBuilder::create()
            ->date('2025-01-01')
            ->debit($this->cash, 5000)
            ->credit($this->revenue, 5000)
            ->commit();

        $report = TrialBalance::generate(null, 'USD', true);

        // All 6 accounts should appear
        $this->assertCount(6, $report['accounts']);
    }

    // -------------------------------------------------------
    // Income Statement (P&L)
    // -------------------------------------------------------

    #[Test]
    public function income_statement_calculates_net_income(): void
    {
        // Revenue: $5,000
        TransactionBuilder::create()
            ->date('2025-01-15')
            ->debit($this->cash, 500000)
            ->credit($this->revenue, 500000)
            ->commit();

        // Expense: $2,000
        TransactionBuilder::create()
            ->date('2025-01-20')
            ->debit($this->expense, 200000)
            ->credit($this->cash, 200000)
            ->commit();

        $report = IncomeStatement::generate(
            Carbon::parse('2025-01-01'),
            Carbon::parse('2025-01-31'),
        );

        $this->assertEquals(500000, $report['total_income']);
        $this->assertEquals(200000, $report['total_expenses']);
        $this->assertEquals(300000, $report['net_income']);
        $this->assertCount(1, $report['income']);
        $this->assertCount(1, $report['expenses']);
    }

    #[Test]
    public function income_statement_respects_date_range(): void
    {
        // January revenue
        TransactionBuilder::create()
            ->date('2025-01-15')
            ->debit($this->cash, 500000)
            ->credit($this->revenue, 500000)
            ->commit();

        // February revenue
        TransactionBuilder::create()
            ->date('2025-02-15')
            ->debit($this->cash, 300000)
            ->credit($this->revenue, 300000)
            ->commit();

        // Only January
        $janReport = IncomeStatement::generate(
            Carbon::parse('2025-01-01'),
            Carbon::parse('2025-01-31'),
        );

        $this->assertEquals(500000, $janReport['total_income']);

        // Only February
        $febReport = IncomeStatement::generate(
            Carbon::parse('2025-02-01'),
            Carbon::parse('2025-02-28'),
        );

        $this->assertEquals(300000, $febReport['total_income']);
    }

    // -------------------------------------------------------
    // Balance Sheet
    // -------------------------------------------------------

    #[Test]
    public function balance_sheet_is_balanced(): void
    {
        // Owner invests $10,000
        TransactionBuilder::create()
            ->date('2025-01-01')
            ->debit($this->cash, 1000000)
            ->credit($this->equity, 1000000)
            ->commit();

        // Earn $5,000
        TransactionBuilder::create()
            ->date('2025-01-15')
            ->debit($this->cash, 500000)
            ->credit($this->revenue, 500000)
            ->commit();

        // Pay $2,000 rent
        TransactionBuilder::create()
            ->date('2025-01-20')
            ->debit($this->expense, 200000)
            ->credit($this->cash, 200000)
            ->commit();

        $report = BalanceSheet::generate(Carbon::parse('2025-01-31'));

        $this->assertTrue($report['is_balanced']);
        // Assets = 10000 - 2000 + 5000 = 13000 = $1,300,000 in cents
        $this->assertEquals(1300000, $report['total_assets']);
        // Liabilities = 0, Equity = 10000 + net income (3000) = 13000
        $this->assertEquals(0, $report['total_liabilities']);
        $this->assertEquals(1300000, $report['total_equity']);
    }

    // -------------------------------------------------------
    // Cash Flow Statement
    // -------------------------------------------------------

    #[Test]
    public function cash_flow_statement_tracks_cash_movements(): void
    {
        // Owner invests (financing)
        TransactionBuilder::create()
            ->date('2025-01-01')
            ->memo('Owner investment')
            ->debit($this->cash, 1000000)
            ->credit($this->equity, 1000000)
            ->commit();

        // Revenue (operating)
        TransactionBuilder::create()
            ->date('2025-01-15')
            ->memo('Cash sale')
            ->debit($this->cash, 500000)
            ->credit($this->revenue, 500000)
            ->commit();

        // Rent expense (operating)
        TransactionBuilder::create()
            ->date('2025-01-20')
            ->memo('Rent payment')
            ->debit($this->expense, 200000)
            ->credit($this->cash, 200000)
            ->commit();

        $report = CashFlowStatement::generate(
            Carbon::parse('2025-01-01'),
            Carbon::parse('2025-01-31'),
        );

        $this->assertEquals(1300000, $report['ending_balance']);
        $this->assertNotEmpty($report['financing']); // Owner investment
        $this->assertNotEmpty($report['operating']); // Revenue and rent
    }

    #[Test]
    public function cash_flow_with_no_cash_accounts_returns_empty(): void
    {
        // Create accounts without any bank sub_type
        $assets = Account::create(['name' => 'Equipment', 'code' => '1500', 'type' => AccountType::ASSET, 'sub_type' => AccountSubType::FIXED_ASSET]);
        $equity = Account::create(['name' => 'Capital', 'code' => '3100', 'type' => AccountType::EQUITY, 'sub_type' => AccountSubType::OWNERS_EQUITY]);

        // Post a transaction that doesn't involve any bank account
        TransactionBuilder::create()
            ->date('2025-01-01')
            ->debit($assets, 1000000)
            ->credit($equity, 1000000)
            ->commit();

        // Generate cash flow with no bank accounts having entries
        // Use date range that only covers the non-cash transaction above
        $report = CashFlowStatement::generate(
            Carbon::parse('2025-01-01'),
            Carbon::parse('2025-01-31'),
        );

        // The setUp cash account exists but has no entries, so no cash flow
        $this->assertEquals(0, $report['net_cash_flow']);
        $this->assertEmpty($report['operating']);
    }

    // -------------------------------------------------------
    // Aging Report
    // -------------------------------------------------------

    #[Test]
    public function aging_report_categorizes_by_age_buckets(): void
    {
        Carbon::setTestNow(Carbon::parse('2025-03-01'));

        // AR entry 10 days old (current)
        $this->ar->debit(100000, 'Invoice 1', Carbon::parse('2025-02-19'));

        // AR entry 45 days old (31-60 bucket)
        $this->ar->debit(200000, 'Invoice 2', Carbon::parse('2025-01-15'));

        // AR entry 75 days old (61-90 bucket)
        $this->ar->debit(300000, 'Invoice 3', Carbon::parse('2024-12-17'));

        $report = AgingReport::generate(AccountType::ASSET);

        $this->assertEquals(600000, $report['total_outstanding']);
        $this->assertNotEmpty($report['details']);
        $this->assertCount(4, $report['summary']); // 4 default buckets

        Carbon::setTestNow();
    }

    #[Test]
    public function aging_report_with_custom_buckets(): void
    {
        Carbon::setTestNow(Carbon::parse('2025-03-01'));

        $this->ar->debit(100000, 'Invoice 1', Carbon::parse('2025-02-19'));
        $this->ar->debit(200000, 'Invoice 2', Carbon::parse('2025-01-15'));

        $customBuckets = [
            ['label' => '0-15 days', 'min' => 0, 'max' => 15],
            ['label' => '16-45 days', 'min' => 16, 'max' => 45],
            ['label' => '46+ days', 'min' => 46, 'max' => null],
        ];

        $report = AgingReport::generate(AccountType::ASSET, null, $customBuckets);

        $this->assertCount(3, $report['summary']);

        Carbon::setTestNow();
    }

    #[Test]
    public function aging_report_excludes_future_dated_entries(): void
    {
        // $asOf is yesterday; entries posted today are future-dated relative to it
        $asOf = Carbon::parse('2025-02-28');
        $futureDate = Carbon::parse('2025-03-05');

        $this->ar->debit(100000, 'Past invoice', Carbon::parse('2025-02-01'));
        $this->ar->debit(50000, 'Future invoice', $futureDate);

        $report = AgingReport::generate(AccountType::ASSET, $asOf);

        // Future-dated invoice should NOT be included
        $this->assertEquals(100000, $report['total_outstanding']);
    }

    #[Test]
    public function aging_report_returns_empty_for_equity_account_type(): void
    {
        // Equity accounts have no sub_type filter; empty result expected with no entries
        $report = AgingReport::generate(AccountType::EQUITY);

        $this->assertEquals(0, $report['total_outstanding']);
        $this->assertEmpty($report['details']);
    }

    // -------------------------------------------------------
    // BalanceSheet period start parameter (H21)
    // -------------------------------------------------------

    #[Test]
    public function balance_sheet_uses_custom_period_start_for_net_income(): void
    {
        // Revenue in Q1
        $this->revenue->credit(10000, 'Q1 sale', Carbon::parse('2025-03-01'));

        // With default period start (Jan 1 2025), net income includes Q1 revenue
        $defaultReport = BalanceSheet::generate(Carbon::parse('2025-06-30'));
        $this->assertGreaterThan(0, $defaultReport['total_equity']);

        // With period start of July 1 2025, Q1 revenue is excluded from net income
        $customReport = BalanceSheet::generate(
            Carbon::parse('2025-12-31'),
            'USD',
            Carbon::parse('2025-07-01'),
        );
        // No revenue posted between Jul-Dec, so net income should be 0
        $this->assertEquals(0, $customReport['total_equity']);
    }
}
