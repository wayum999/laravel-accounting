<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use Tests\TestCase;
use App\Accounting\Models\Account;
use App\Accounting\Models\AccountType;
use App\Accounting\Enums\AccountCategory;
use App\Accounting\Services\FinancialReports\AgingReport;
use App\Accounting\Transaction;
use Money\Money;
use Money\Currency;
use Carbon\Carbon;

class AgingReportTest extends TestCase
{
    private AccountType $arType;
    private AccountType $apType;
    private Account $arAccount;
    private Account $apAccount;
    private Account $cashAccount;
    private Account $revenueAccount;

    protected function setUp(): void
    {
        parent::setUp();

        $assetType = AccountType::create([
            'name' => 'Assets', 'type' => AccountCategory::ASSET, 'code' => 'ASSET',
        ]);

        $this->arType = AccountType::create([
            'name' => 'Accounts Receivable', 'type' => AccountCategory::ASSET, 'code' => 'AR',
        ]);

        $this->apType = AccountType::create([
            'name' => 'Accounts Payable', 'type' => AccountCategory::LIABILITY, 'code' => 'AP',
        ]);

        $incomeType = AccountType::create([
            'name' => 'Income', 'type' => AccountCategory::INCOME, 'code' => 'INCOME',
        ]);

        $defaults = ['currency' => 'USD', 'morphed_type' => 'system', 'morphed_id' => 0];

        $this->cashAccount = Account::create(array_merge($defaults, [
            'account_type_id' => $assetType->id, 'name' => 'Cash', 'number' => '1000',
        ]));

        $this->arAccount = Account::create(array_merge($defaults, [
            'account_type_id' => $this->arType->id, 'name' => 'Trade Receivables', 'number' => '1100',
        ]));

        $this->apAccount = Account::create(array_merge($defaults, [
            'account_type_id' => $this->apType->id, 'name' => 'Trade Payables', 'number' => '2000',
        ]));

        $this->revenueAccount = Account::create(array_merge($defaults, [
            'account_type_id' => $incomeType->id, 'name' => 'Revenue', 'number' => '4000',
        ]));
    }

    private function createTransaction(
        Account $debitAccount,
        Account $creditAccount,
        int $amount,
        ?Carbon $postDate = null
    ): void {
        $money = new Money($amount, new Currency('USD'));
        $txn   = Transaction::newDoubleEntryTransactionGroup();
        $txn->addTransaction($debitAccount, 'debit', $money, 'Test', null, $postDate);
        $txn->addTransaction($creditAccount, 'credit', $money, 'Test', null, $postDate);
        $txn->commit();
    }

    public function test_aging_report_buckets_correctly(): void
    {
        $asOf = Carbon::parse('2025-03-31');

        // Current (10 days old)
        $this->createTransaction($this->arAccount, $this->revenueAccount, 10000, Carbon::parse('2025-03-21'));
        // 31-60 days (45 days old)
        $this->createTransaction($this->arAccount, $this->revenueAccount, 20000, Carbon::parse('2025-02-14'));
        // 61-90 days (75 days old)
        $this->createTransaction($this->arAccount, $this->revenueAccount, 30000, Carbon::parse('2025-01-15'));

        $report = AgingReport::generate($this->arType, $asOf);

        $this->assertEquals(60000, $report['total_outstanding']);
        $this->assertCount(5, $report['summary']); // 5 default buckets
    }

    public function test_aging_report_with_custom_buckets(): void
    {
        $asOf = Carbon::parse('2025-03-31');
        $this->createTransaction($this->arAccount, $this->revenueAccount, 10000, Carbon::parse('2025-03-25'));

        $customBuckets = [
            ['min' => 0, 'max' => 15, 'label' => '0-15 days'],
            ['min' => 16, 'max' => 45, 'label' => '16-45 days'],
            ['min' => 46, 'max' => null, 'label' => '46+ days'],
        ];

        $report = AgingReport::generate($this->arType, $asOf, $customBuckets);

        $this->assertCount(3, $report['summary']);
        $labels = array_column($report['summary'], 'label');
        $this->assertEquals(['0-15 days', '16-45 days', '46+ days'], $labels);
    }

    public function test_aging_report_summary_percentages(): void
    {
        $asOf = Carbon::parse('2025-03-31');

        // 50% current, 50% 31-60
        $this->createTransaction($this->arAccount, $this->revenueAccount, 50000, Carbon::parse('2025-03-25'));
        $this->createTransaction($this->arAccount, $this->revenueAccount, 50000, Carbon::parse('2025-02-20'));

        $report = AgingReport::generate($this->arType, $asOf);

        $percentages = [];
        foreach ($report['summary'] as $bucket) {
            if ($bucket['amount'] > 0) {
                $percentages[$bucket['label']] = $bucket['percentage'];
            }
        }

        $this->assertEquals(50.0, $percentages['Current']);
        $this->assertEquals(50.0, $percentages['31-60']);
    }

    public function test_aging_report_empty_when_no_entries(): void
    {
        $report = AgingReport::generate($this->arType);

        $this->assertEquals(0, $report['total_outstanding']);
        $this->assertEmpty($report['details']);
    }

    public function test_aging_report_excludes_zero_balance_groups(): void
    {
        $asOf = Carbon::parse('2025-03-31');

        // Create a receivable and then pay it off.
        $this->createTransaction($this->arAccount, $this->revenueAccount, 10000, Carbon::parse('2025-03-15'));
        // Payment received (credit AR, debit Cash).
        $this->createTransaction($this->cashAccount, $this->arAccount, 10000, Carbon::parse('2025-03-20'));

        $report = AgingReport::generate($this->arType, $asOf);

        // Net balance is zero, so the group should be excluded.
        $this->assertEquals(0, $report['total_outstanding']);
    }

    public function test_payable_aging(): void
    {
        $asOf = Carbon::parse('2025-03-31');

        // Create payable (credit AP).
        $this->createTransaction($this->cashAccount, $this->apAccount, 25000, Carbon::parse('2025-03-10'));

        $report = AgingReport::generate($this->apType, $asOf);

        $this->assertEquals(25000, $report['total_outstanding']);
    }

    public function test_aging_report_returns_correct_structure(): void
    {
        $report = AgingReport::generate($this->arType);

        $this->assertArrayHasKey('as_of', $report);
        $this->assertArrayHasKey('account_type', $report);
        $this->assertArrayHasKey('category', $report);
        $this->assertArrayHasKey('currency', $report);
        $this->assertArrayHasKey('details', $report);
        $this->assertArrayHasKey('summary', $report);
        $this->assertArrayHasKey('total_outstanding', $report);
    }
}
