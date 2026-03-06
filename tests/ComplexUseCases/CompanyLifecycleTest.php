<?php

declare(strict_types=1);

namespace Tests\ComplexUseCases;

use App\Accounting\Enums\AccountSubType;
use App\Accounting\Enums\AccountType;
use App\Accounting\Models\Account;
use App\Accounting\Services\ChartOfAccountsSeeder;
use App\Accounting\Services\Reports\BalanceSheet;
use App\Accounting\Services\Reports\IncomeStatement;
use App\Accounting\Services\Reports\TrialBalance;
use App\Accounting\Services\TransactionBuilder;
use Carbon\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class CompanyLifecycleTest extends TestCase
{
    /**
     * Complete company lifecycle:
     * 1. Owner invests capital
     * 2. Purchase equipment
     * 3. Receive loan
     * 4. Earn revenue
     * 5. Pay expenses
     * 6. Collect receivables
     * 7. Pay off loan partially
     * 8. Close books - verify accounting equation holds
     */
    #[Test]
    public function full_company_lifecycle(): void
    {
        // Seed a minimal chart of accounts
        ChartOfAccountsSeeder::seedMinimal();

        $cash = Account::where('code', '1000')->first();
        $ar = Account::where('code', '1100')->first();
        $ap = Account::where('code', '2000')->first();
        $equity = Account::where('code', '3000')->first();
        $retained = Account::where('code', '3100')->first();
        $revenue = Account::where('code', '4000')->first();
        $expenses = Account::where('code', '5000')->first();

        // Add a few more accounts for this scenario
        $equipment = Account::create(['name' => 'Equipment', 'code' => '1500', 'type' => AccountType::ASSET, 'sub_type' => AccountSubType::FIXED_ASSET]);
        $loan = Account::create(['name' => 'Bank Loan', 'code' => '2500', 'type' => AccountType::LIABILITY, 'sub_type' => AccountSubType::LONG_TERM_LIABILITY]);

        // -------------------------------------------------------
        // 1. Owner invests $50,000 on Jan 1
        // -------------------------------------------------------
        TransactionBuilder::create()
            ->date('2025-01-01')
            ->memo('Owner capital investment')
            ->reference('CAP-001')
            ->debit($cash, 5000000)
            ->credit($equity, 5000000)
            ->commit();

        $this->assertEquals(5000000, (int) $cash->getBalance()->getAmount());
        $this->assertEquals(5000000, (int) $equity->getBalance()->getAmount());

        // -------------------------------------------------------
        // 2. Purchase equipment for $15,000 on Jan 5
        // -------------------------------------------------------
        TransactionBuilder::create()
            ->date('2025-01-05')
            ->memo('Office equipment purchase')
            ->reference('PO-001')
            ->debit($equipment, 1500000)
            ->credit($cash, 1500000)
            ->commit();

        $this->assertEquals(3500000, (int) $cash->getBalance()->getAmount());
        $this->assertEquals(1500000, (int) $equipment->getBalance()->getAmount());

        // -------------------------------------------------------
        // 3. Receive bank loan of $20,000 on Jan 10
        // -------------------------------------------------------
        TransactionBuilder::create()
            ->date('2025-01-10')
            ->memo('Bank loan received')
            ->reference('LOAN-001')
            ->debit($cash, 2000000)
            ->credit($loan, 2000000)
            ->commit();

        $this->assertEquals(5500000, (int) $cash->getBalance()->getAmount());
        $this->assertEquals(2000000, (int) $loan->getBalance()->getAmount());

        // -------------------------------------------------------
        // 4. Earn revenue $12,000 on account (not cash) on Jan 15
        // -------------------------------------------------------
        TransactionBuilder::create()
            ->date('2025-01-15')
            ->memo('Service rendered - Invoice #101')
            ->reference('INV-101')
            ->debit($ar, 1200000)
            ->credit($revenue, 1200000)
            ->commit();

        $this->assertEquals(1200000, (int) $ar->getBalance()->getAmount());
        $this->assertEquals(1200000, (int) $revenue->getBalance()->getAmount());

        // -------------------------------------------------------
        // 5. Pay expenses $3,000 on Jan 20
        // -------------------------------------------------------
        TransactionBuilder::create()
            ->date('2025-01-20')
            ->memo('Office rent January')
            ->reference('EXP-001')
            ->debit($expenses, 300000)
            ->credit($cash, 300000)
            ->commit();

        $this->assertEquals(5200000, (int) $cash->getBalance()->getAmount());
        $this->assertEquals(300000, (int) $expenses->getBalance()->getAmount());

        // -------------------------------------------------------
        // 6. Collect $8,000 of the $12,000 receivable on Jan 25
        // -------------------------------------------------------
        TransactionBuilder::create()
            ->date('2025-01-25')
            ->memo('Payment received on INV-101')
            ->reference('PMT-001')
            ->debit($cash, 800000)
            ->credit($ar, 800000)
            ->commit();

        $this->assertEquals(6000000, (int) $cash->getBalance()->getAmount());
        $this->assertEquals(400000, (int) $ar->getBalance()->getAmount()); // $4,000 still outstanding

        // -------------------------------------------------------
        // 7. Pay off $5,000 of the loan on Jan 28
        // -------------------------------------------------------
        TransactionBuilder::create()
            ->date('2025-01-28')
            ->memo('Loan payment')
            ->reference('LOAN-PMT-001')
            ->debit($loan, 500000)
            ->credit($cash, 500000)
            ->commit();

        $this->assertEquals(5500000, (int) $cash->getBalance()->getAmount());
        $this->assertEquals(1500000, (int) $loan->getBalance()->getAmount()); // $15,000 remaining

        // -------------------------------------------------------
        // 8. Verify the accounting equation: Assets = Liabilities + Equity
        // -------------------------------------------------------

        // Assets: Cash($55,000) + AR($4,000) + Equipment($15,000) = $74,000
        $totalAssets = (int) $cash->getBalance()->getAmount()
            + (int) $ar->getBalance()->getAmount()
            + (int) $equipment->getBalance()->getAmount();
        $this->assertEquals(7400000, $totalAssets);

        // Liabilities: AP($0) + Loan($15,000) = $15,000
        $totalLiabilities = (int) $ap->getBalance()->getAmount()
            + (int) $loan->getBalance()->getAmount();
        $this->assertEquals(1500000, $totalLiabilities);

        // Equity: Owner's Equity($50,000) + Net Income($9,000) = $59,000
        // Net Income = Revenue($12,000) - Expenses($3,000) = $9,000
        $netIncome = (int) $revenue->getBalance()->getAmount() - (int) $expenses->getBalance()->getAmount();
        $this->assertEquals(900000, $netIncome);

        $totalEquity = (int) $equity->getBalance()->getAmount() + $netIncome;
        $this->assertEquals(5900000, $totalEquity);

        // Accounting equation: Assets = Liabilities + Equity
        $this->assertEquals($totalAssets, $totalLiabilities + $totalEquity);

        // -------------------------------------------------------
        // 9. Verify reports match
        // -------------------------------------------------------

        // Trial Balance
        $trialBalance = TrialBalance::generate(Carbon::parse('2025-01-31'));
        $this->assertTrue($trialBalance['is_balanced']);

        // Income Statement
        $pl = IncomeStatement::generate(
            Carbon::parse('2025-01-01'),
            Carbon::parse('2025-01-31'),
        );
        $this->assertEquals(1200000, $pl['total_revenue']);
        $this->assertEquals(300000, $pl['total_operating_expenses']);
        $this->assertEquals(900000, $pl['net_income']);

        // Balance Sheet
        $bs = BalanceSheet::generate(Carbon::parse('2025-01-31'));
        $this->assertTrue($bs['is_balanced']);
        $this->assertEquals($bs['total_assets'], $bs['total_liabilities'] + $bs['total_equity']);
    }

    /**
     * Test voiding and reversing transactions maintains balance.
     */
    #[Test]
    public function void_and_reverse_maintain_accounting_equation(): void
    {
        $cash = Account::create(['name' => 'Cash', 'code' => '1000', 'type' => AccountType::ASSET, 'sub_type' => AccountSubType::BANK]);
        $revenue = Account::create(['name' => 'Revenue', 'code' => '4000', 'type' => AccountType::REVENUE]);
        $expense = Account::create(['name' => 'Expense', 'code' => '5000', 'type' => AccountType::EXPENSE]);

        // Create a transaction
        $je1 = TransactionBuilder::create()
            ->date('2025-01-15')
            ->memo('Sale')
            ->debit($cash, 500000)
            ->credit($revenue, 500000)
            ->commit();

        // Create another transaction
        $je2 = TransactionBuilder::create()
            ->date('2025-01-20')
            ->memo('Expense')
            ->debit($expense, 200000)
            ->credit($cash, 200000)
            ->commit();

        // Void the first transaction (the sale)
        $je1->void();

        // After voiding: Cash should be -$2,000 (the expense withdrew cash but the sale was voided)
        $cash->refresh();
        $revenue->refresh();

        $this->assertEquals(-200000, (int) $cash->getBalance()->getAmount());
        $this->assertEquals(0, (int) $revenue->getBalance()->getAmount());
        $this->assertEquals(200000, (int) $expense->getBalance()->getAmount());

        // Trial balance should still be balanced
        $tb = TrialBalance::generate(Carbon::parse('2025-01-31'));
        $this->assertTrue($tb['is_balanced']);
    }

    /**
     * Test multiple currencies don't interfere with each other.
     */
    #[Test]
    public function multi_currency_accounts_stay_separate(): void
    {
        $cashUsd = Account::create(['name' => 'Cash USD', 'code' => '1000', 'type' => AccountType::ASSET, 'currency' => 'USD', 'sub_type' => AccountSubType::BANK]);
        $cashEur = Account::create(['name' => 'Cash EUR', 'code' => '1001', 'type' => AccountType::ASSET, 'currency' => 'EUR', 'sub_type' => AccountSubType::BANK]);
        $revenueUsd = Account::create(['name' => 'Revenue USD', 'code' => '4000', 'type' => AccountType::REVENUE, 'currency' => 'USD']);
        $revenueEur = Account::create(['name' => 'Revenue EUR', 'code' => '4001', 'type' => AccountType::REVENUE, 'currency' => 'EUR']);

        TransactionBuilder::create()
            ->debit($cashUsd, 500000)
            ->credit($revenueUsd, 500000)
            ->commit();

        TransactionBuilder::create()
            ->debit($cashEur, 300000)
            ->credit($revenueEur, 300000)
            ->commit();

        // USD trial balance
        $usdTb = TrialBalance::generate(null, 'USD');
        $this->assertTrue($usdTb['is_balanced']);
        $this->assertEquals(500000, $usdTb['total_debits']);

        // EUR trial balance
        $eurTb = TrialBalance::generate(null, 'EUR');
        $this->assertTrue($eurTb['is_balanced']);
        $this->assertEquals(300000, $eurTb['total_debits']);
    }

    /**
     * Test the HasAccounting trait with a real workflow.
     */
    #[Test]
    public function polymorphic_account_ownership(): void
    {
        // Simulate a "customer" owning an AR sub-account
        // Using Account as a stand-in for any Eloquent model

        $customerAccount = Account::create([
            'name' => 'Customer AR - Acme Corp',
            'code' => '1100-001',
            'type' => AccountType::ASSET,
            'sub_type' => AccountSubType::ACCOUNTS_RECEIVABLE,
        ]);

        $cash = Account::create(['name' => 'Cash', 'code' => '1000', 'type' => AccountType::ASSET, 'sub_type' => AccountSubType::BANK]);
        $revenue = Account::create(['name' => 'Revenue', 'code' => '4000', 'type' => AccountType::REVENUE]);

        // Invoice the customer: DR AR, CR Revenue
        $invoice = TransactionBuilder::create()
            ->date('2025-01-15')
            ->memo('Invoice #1001 - Acme Corp')
            ->reference('INV-1001')
            ->debit($customerAccount, 250000)
            ->credit($revenue, 250000)
            ->commit();

        $this->assertEquals(250000, (int) $customerAccount->getBalance()->getAmount());

        // Customer pays: DR Cash, CR AR
        TransactionBuilder::create()
            ->date('2025-01-25')
            ->memo('Payment received - Acme Corp')
            ->reference('PMT-1001')
            ->debit($cash, 250000)
            ->credit($customerAccount, 250000)
            ->commit();

        $this->assertEquals(0, (int) $customerAccount->getBalance()->getAmount());
        $this->assertEquals(250000, (int) $cash->getBalance()->getAmount());
    }

    /**
     * Test ledgerable polymorphic references on entries.
     */
    #[Test]
    public function ledger_entries_reference_related_models(): void
    {
        $cash = Account::create(['name' => 'Cash', 'code' => '1000', 'type' => AccountType::ASSET, 'sub_type' => AccountSubType::BANK]);
        $revenue = Account::create(['name' => 'Revenue', 'code' => '4000', 'type' => AccountType::REVENUE]);

        // Use an Account model as a stand-in for "Invoice #101"
        $invoiceRef = Account::create(['name' => 'Invoice Reference', 'type' => AccountType::ASSET]);

        $je = TransactionBuilder::create()
            ->date('2025-01-15')
            ->memo('Sale for Invoice #101')
            ->debit($cash, 500000, 'Cash from invoice', $invoiceRef)
            ->credit($revenue, 500000, 'Revenue from invoice', $invoiceRef)
            ->commit();

        // Both entries should reference the invoice (stored as the morph alias)
        foreach ($je->ledgerEntries as $entry) {
            $this->assertEquals($invoiceRef->getMorphClass(), $entry->ledgerable_type);
            $this->assertEquals($invoiceRef->id, $entry->ledgerable_id);

            $resolved = $entry->getReferencedModel();
            $this->assertNotNull($resolved);
            $this->assertEquals($invoiceRef->id, $resolved->id);
        }

        // Query entries referencing the invoice from the cash account
        $referenced = $cash->entriesReferencingModel($invoiceRef)->get();
        $this->assertCount(1, $referenced);
    }
}
