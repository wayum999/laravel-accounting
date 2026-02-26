<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Accounting\Enums\AccountCategory;
use App\Accounting\Models\Account;
use App\Accounting\Models\AccountType;
use App\Accounting\Services\FinancialReports\BalanceSheet;
use App\Accounting\Services\FinancialReports\IncomeStatement;
use App\Accounting\Services\FinancialReports\TrialBalance;
use App\Accounting\Transaction;
use Carbon\Carbon;
use Money\Currency;
use Money\Money;
use Tests\TestCase;

class FinancialReportsTest extends TestCase
{
    private Account $cashAccount;
    private Account $arAccount;
    private Account $apAccount;
    private Account $equityAccount;
    private Account $revenueAccount;
    private Account $expenseAccount;

    protected function setUp(): void
    {
        parent::setUp();

        $assetType = AccountType::create([
            'name' => 'Assets', 'type' => AccountCategory::ASSET, 'code' => 'ASSET',
        ]);
        $liabilityType = AccountType::create([
            'name' => 'Liabilities', 'type' => AccountCategory::LIABILITY, 'code' => 'LIAB',
        ]);
        $equityType = AccountType::create([
            'name' => 'Equity', 'type' => AccountCategory::EQUITY, 'code' => 'EQUITY',
        ]);
        $incomeType = AccountType::create([
            'name' => 'Income', 'type' => AccountCategory::INCOME, 'code' => 'INCOME',
        ]);
        $expenseType = AccountType::create([
            'name' => 'Expenses', 'type' => AccountCategory::EXPENSE, 'code' => 'EXPENSE',
        ]);

        $defaults = ['currency' => 'USD', 'morphed_type' => 'system', 'morphed_id' => 0];

        $this->cashAccount = Account::create(array_merge($defaults, [
            'account_type_id' => $assetType->id, 'name' => 'Cash', 'number' => '1000',
        ]));
        $this->arAccount = Account::create(array_merge($defaults, [
            'account_type_id' => $assetType->id, 'name' => 'Accounts Receivable', 'number' => '1100',
        ]));
        $this->apAccount = Account::create(array_merge($defaults, [
            'account_type_id' => $liabilityType->id, 'name' => 'Accounts Payable', 'number' => '2000',
        ]));
        $this->equityAccount = Account::create(array_merge($defaults, [
            'account_type_id' => $equityType->id, 'name' => 'Owner Capital', 'number' => '3000',
        ]));
        $this->revenueAccount = Account::create(array_merge($defaults, [
            'account_type_id' => $incomeType->id, 'name' => 'Service Revenue', 'number' => '4000',
        ]));
        $this->expenseAccount = Account::create(array_merge($defaults, [
            'account_type_id' => $expenseType->id, 'name' => 'Rent Expense', 'number' => '5000',
        ]));
    }

    // -------------------------------------------------------
    // Helper
    // -------------------------------------------------------

    private function createBalancedTransaction(
        Account $debitAccount,
        Account $creditAccount,
        int $amount,
        ?Carbon $postDate = null
    ): string {
        $money = new Money($amount, new Currency('USD'));
        $txn = Transaction::newDoubleEntryTransactionGroup();
        $txn->addTransaction($debitAccount, 'debit', $money, 'Test', null, $postDate);
        $txn->addTransaction($creditAccount, 'credit', $money, 'Test', null, $postDate);
        return $txn->commit();
    }

    // -------------------------------------------------------
    // Trial Balance
    // -------------------------------------------------------

    public function test_trial_balance_is_balanced(): void
    {
        $this->createBalancedTransaction($this->cashAccount, $this->equityAccount, 100000);
        $this->createBalancedTransaction($this->cashAccount, $this->revenueAccount, 50000);
        $this->createBalancedTransaction($this->expenseAccount, $this->cashAccount, 20000);

        $report = TrialBalance::generate();

        $this->assertTrue($report['is_balanced']);
        $this->assertEquals($report['total_debits'], $report['total_credits']);
    }

    public function test_trial_balance_correct_amounts(): void
    {
        // Owner invests $1,000
        $this->createBalancedTransaction($this->cashAccount, $this->equityAccount, 100000);
        // Earn $500 in revenue
        $this->createBalancedTransaction($this->cashAccount, $this->revenueAccount, 50000);
        // Pay $200 in expenses
        $this->createBalancedTransaction($this->expenseAccount, $this->cashAccount, 20000);

        $report = TrialBalance::generate();

        // Cash: 100,000 + 50,000 − 20,000 = 130,000 (debit-normal → debit column)
        $cashRow = collect($report['accounts'])->firstWhere('account_number', '1000');
        $this->assertEquals(130000, $cashRow['debit']);
        $this->assertEquals(0, $cashRow['credit']);

        // Revenue: 50,000 (credit-normal → credit column)
        $revenueRow = collect($report['accounts'])->firstWhere('account_number', '4000');
        $this->assertEquals(0, $revenueRow['debit']);
        $this->assertEquals(50000, $revenueRow['credit']);

        // Expense: 20,000 (debit-normal → debit column)
        $expenseRow = collect($report['accounts'])->firstWhere('account_number', '5000');
        $this->assertEquals(20000, $expenseRow['debit']);
        $this->assertEquals(0, $expenseRow['credit']);
    }

    public function test_trial_balance_excludes_zero_balances_by_default(): void
    {
        $this->createBalancedTransaction($this->cashAccount, $this->equityAccount, 100000);

        $report = TrialBalance::generate();

        $accountNumbers = collect($report['accounts'])->pluck('account_number')->toArray();
        $this->assertContains('1000', $accountNumbers);
        $this->assertNotContains('1100', $accountNumbers); // AR has a zero balance
    }

    public function test_trial_balance_includes_zero_balances_when_requested(): void
    {
        $this->createBalancedTransaction($this->cashAccount, $this->equityAccount, 100000);

        $report = TrialBalance::generate(null, 'USD', true);

        $accountNumbers = collect($report['accounts'])->pluck('account_number')->toArray();
        $this->assertContains('1100', $accountNumbers);
    }

    public function test_trial_balance_as_of_date(): void
    {
        $this->createBalancedTransaction(
            $this->cashAccount, $this->equityAccount, 100000, Carbon::parse('2025-01-15')
        );
        $this->createBalancedTransaction(
            $this->cashAccount, $this->revenueAccount, 50000, Carbon::parse('2025-02-15')
        );

        $janReport = TrialBalance::generate(Carbon::parse('2025-01-31'));

        // Only the January transaction should be included
        $cashRow = collect($janReport['accounts'])->firstWhere('account_number', '1000');
        $this->assertEquals(100000, $cashRow['debit']);
        $this->assertTrue($janReport['is_balanced']);
    }

    // -------------------------------------------------------
    // Income Statement
    // -------------------------------------------------------

    public function test_income_statement_calculates_net_income(): void
    {
        $date = Carbon::parse('2025-01-15');
        $this->createBalancedTransaction($this->cashAccount, $this->revenueAccount, 80000, $date);
        $this->createBalancedTransaction($this->expenseAccount, $this->cashAccount, 30000, $date);

        $report = IncomeStatement::generate(
            Carbon::parse('2025-01-01'),
            Carbon::parse('2025-01-31')
        );

        $this->assertEquals(80000, $report['total_income']);
        $this->assertEquals(30000, $report['total_expenses']);
        $this->assertEquals(50000, $report['net_income']);
    }

    public function test_income_statement_respects_date_range(): void
    {
        $this->createBalancedTransaction(
            $this->cashAccount, $this->revenueAccount, 40000, Carbon::parse('2025-01-15')
        );
        $this->createBalancedTransaction(
            $this->cashAccount, $this->revenueAccount, 60000, Carbon::parse('2025-02-15')
        );

        $janReport = IncomeStatement::generate(
            Carbon::parse('2025-01-01'),
            Carbon::parse('2025-01-31')
        );

        $this->assertEquals(40000, $janReport['total_income']);
    }

    public function test_income_statement_with_no_activity(): void
    {
        $report = IncomeStatement::generate(
            Carbon::parse('2025-01-01'),
            Carbon::parse('2025-01-31')
        );

        $this->assertEquals(0, $report['total_income']);
        $this->assertEquals(0, $report['total_expenses']);
        $this->assertEquals(0, $report['net_income']);
    }

    // -------------------------------------------------------
    // Balance Sheet
    // -------------------------------------------------------

    public function test_balance_sheet_has_required_keys(): void
    {
        $this->createBalancedTransaction($this->cashAccount, $this->equityAccount, 100000);
        $this->createBalancedTransaction($this->cashAccount, $this->revenueAccount, 50000);
        $this->createBalancedTransaction($this->expenseAccount, $this->cashAccount, 20000);

        $report = BalanceSheet::generate();

        $this->assertArrayHasKey('assets', $report);
        $this->assertArrayHasKey('liabilities', $report);
        $this->assertArrayHasKey('equity', $report);
        $this->assertArrayHasKey('total_assets', $report);
        $this->assertArrayHasKey('total_liabilities', $report);
        $this->assertArrayHasKey('total_equity', $report);
        $this->assertArrayHasKey('is_balanced', $report);
    }

    public function test_balance_sheet_balanced_without_income_expense(): void
    {
        // Pure balance-sheet transactions keep A = L + E
        $this->createBalancedTransaction($this->cashAccount, $this->equityAccount, 100000);
        $this->createBalancedTransaction($this->cashAccount, $this->apAccount, 50000);

        $report = BalanceSheet::generate();

        $this->assertEquals(150000, $report['total_assets']);      // Cash: 150,000
        $this->assertEquals(50000, $report['total_liabilities']);   // AP: 50,000
        $this->assertEquals(100000, $report['total_equity']);       // Owner Capital: 100,000
        $this->assertTrue($report['is_balanced']);                  // 150,000 = 50,000 + 100,000
    }

    public function test_balance_sheet_as_of_date(): void
    {
        $this->createBalancedTransaction(
            $this->cashAccount, $this->equityAccount, 100000, Carbon::parse('2025-01-15')
        );
        $this->createBalancedTransaction(
            $this->cashAccount, $this->apAccount, 50000, Carbon::parse('2025-02-15')
        );

        $janReport = BalanceSheet::generate(Carbon::parse('2025-01-31'));

        $this->assertEquals(100000, $janReport['total_assets']);
        $this->assertEquals(0, $janReport['total_liabilities']);
        $this->assertEquals(100000, $janReport['total_equity']);
        $this->assertTrue($janReport['is_balanced']);
    }

    public function test_balance_sheet_empty(): void
    {
        $report = BalanceSheet::generate();

        $this->assertEquals(0, $report['total_assets']);
        $this->assertEquals(0, $report['total_liabilities']);
        $this->assertEquals(0, $report['total_equity']);
        $this->assertTrue($report['is_balanced']); // 0 = 0 + 0
    }
}
