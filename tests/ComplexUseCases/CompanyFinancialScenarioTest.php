<?php

declare(strict_types=1);

namespace Tests\ComplexUseCases;

use Carbon\Carbon;
use Tests\TestCase;
use App\Accounting\Models\AccountType;
use App\Accounting\Models\Account;
use App\Accounting\Enums\AccountCategory;
use App\Accounting\Transaction;
use Money\Money;
use Money\Currency;

class CompanyFinancialScenarioTest extends TestCase
{
    /**
     * This test simulates a comprehensive financial scenario for a company
     * that utilizes all types of account categories over time.
     */
    public function test_company_financial_scenario(): void
    {
        // ======================
        // 1. Company Setup
        // ======================

        // Create all necessary account types
        $assetAccountTypes = [
            'cash' => AccountType::create(['name' => 'Cash', 'type' => AccountCategory::ASSET]),
            'accounts_receivable' => AccountType::create(['name' => 'Accounts Receivable', 'type' => AccountCategory::ASSET]),
            'inventory' => AccountType::create(['name' => 'Inventory', 'type' => AccountCategory::ASSET]),
            'equipment' => AccountType::create(['name' => 'Equipment', 'type' => AccountCategory::ASSET]),
        ];

        $liabilityAccountTypes = [
            'accounts_payable' => AccountType::create(['name' => 'Accounts Payable', 'type' => AccountCategory::LIABILITY]),
            'loans_payable' => AccountType::create(['name' => 'Loans Payable', 'type' => AccountCategory::LIABILITY]),
        ];

        $equityAccountTypes = [
            'common_stock' => AccountType::create(['name' => 'Common Stock', 'type' => AccountCategory::EQUITY]),
            'retained_earnings' => AccountType::create(['name' => 'Retained Earnings', 'type' => AccountCategory::EQUITY]),
        ];

        // INCOME replaces REVENUE; gains are folded into INCOME
        $incomeAccountTypes = [
            'product_sales' => AccountType::create(['name' => 'Product Sales', 'type' => AccountCategory::INCOME]),
            'service_revenue' => AccountType::create(['name' => 'Service Revenue', 'type' => AccountCategory::INCOME]),
            'sale_of_asset' => AccountType::create(['name' => 'Gain on Sale of Asset', 'type' => AccountCategory::INCOME]),
        ];

        // EXPENSE; losses are folded into EXPENSE
        $expenseAccountTypes = [
            'cogs' => AccountType::create(['name' => 'Cost of Goods Sold', 'type' => AccountCategory::EXPENSE]),
            'salaries' => AccountType::create(['name' => 'Salaries Expense', 'type' => AccountCategory::EXPENSE]),
            'rent' => AccountType::create(['name' => 'Rent Expense', 'type' => AccountCategory::EXPENSE]),
            'utilities' => AccountType::create(['name' => 'Utilities Expense', 'type' => AccountCategory::EXPENSE]),
            'depreciation' => AccountType::create(['name' => 'Depreciation Expense', 'type' => AccountCategory::EXPENSE]),
            'inventory_shrinkage' => AccountType::create(['name' => 'Inventory Shrinkage', 'type' => AccountCategory::EXPENSE]),
        ];

        // Create one Account per AccountType
        $accounts = [];
        $allAccountTypes = array_merge(
            $assetAccountTypes, $liabilityAccountTypes, $equityAccountTypes,
            $incomeAccountTypes, $expenseAccountTypes
        );

        foreach ($allAccountTypes as $key => $accountType) {
            $accounts[$key] = $accountType->accounts()->create([
                'currency' => 'USD',
                'morphed_type' => 'account_type',
                'morphed_id' => $accountType->id,
            ]);
        }

        // ======================
        // 2. Business Transactions
        // ======================

        // Transaction 1: Initial investment and loan
        $this->recordTransaction([
            ['account' => $accounts['common_stock'], 'method' => 'credit', 'amount' => 100000, 'memo' => 'Initial investment'],
            ['account' => $accounts['loans_payable'], 'method' => 'credit', 'amount' => 50000, 'memo' => 'Bank loan'],
            ['account' => $accounts['cash'], 'method' => 'debit', 'amount' => 150000, 'memo' => 'Initial capital'],
        ]);

        // Transaction 2: Purchase inventory on account
        $this->recordTransaction([
            ['account' => $accounts['inventory'], 'method' => 'debit', 'amount' => 40000, 'memo' => 'Purchase inventory'],
            ['account' => $accounts['accounts_payable'], 'method' => 'credit', 'amount' => 40000, 'memo' => 'Owe for inventory'],
        ]);

        // Transaction 3: Purchase equipment with cash
        $this->recordTransaction([
            ['account' => $accounts['equipment'], 'method' => 'debit', 'amount' => 60000, 'memo' => 'Purchase equipment'],
            ['account' => $accounts['cash'], 'method' => 'credit', 'amount' => 60000, 'memo' => 'Pay for equipment'],
        ]);

        // Transaction 4: Pay rent for the month
        $this->recordTransaction([
            ['account' => $accounts['rent'], 'method' => 'debit', 'amount' => 5000, 'memo' => 'Monthly rent'],
            ['account' => $accounts['cash'], 'method' => 'credit', 'amount' => 5000, 'memo' => 'Pay rent'],
        ]);

        // Transaction 5: Sell inventory for cash and on account
        $this->recordTransaction([
            ['account' => $accounts['cash'], 'method' => 'debit', 'amount' => 35000, 'memo' => 'Cash sales'],
            ['account' => $accounts['accounts_receivable'], 'method' => 'debit', 'amount' => 25000, 'memo' => 'Credit sales'],
            ['account' => $accounts['product_sales'], 'method' => 'credit', 'amount' => 60000, 'memo' => 'Revenue from sales'],
        ]);

        // Record COGS
        $this->recordTransaction([
            ['account' => $accounts['cogs'], 'method' => 'debit', 'amount' => 30000, 'memo' => 'COGS for sales'],
            ['account' => $accounts['inventory'], 'method' => 'credit', 'amount' => 30000, 'memo' => 'Reduce inventory for sales'],
        ]);

        // Transaction 6: Pay salaries
        $this->recordTransaction([
            ['account' => $accounts['salaries'], 'method' => 'debit', 'amount' => 15000, 'memo' => 'Monthly salaries'],
            ['account' => $accounts['cash'], 'method' => 'credit', 'amount' => 15000, 'memo' => 'Pay salaries'],
        ]);

        // Transaction 7: Pay utilities
        $this->recordTransaction([
            ['account' => $accounts['utilities'], 'method' => 'debit', 'amount' => 2000, 'memo' => 'Monthly utilities'],
            ['account' => $accounts['cash'], 'method' => 'credit', 'amount' => 2000, 'memo' => 'Pay utilities'],
        ]);

        // Transaction 8: Record depreciation
        $this->recordTransaction([
            ['account' => $accounts['depreciation'], 'method' => 'debit', 'amount' => 1000, 'memo' => 'Monthly depreciation'],
            ['account' => $accounts['equipment'], 'method' => 'credit', 'amount' => 1000, 'memo' => 'Accumulated depreciation'],
        ]);

        // Transaction 9: Sell equipment at a gain (gain is INCOME)
        $this->recordTransaction([
            ['account' => $accounts['cash'], 'method' => 'debit', 'amount' => 55000, 'memo' => 'Proceeds from equipment sale'],
            ['account' => $accounts['equipment'], 'method' => 'credit', 'amount' => 50000, 'memo' => 'Remove equipment at book value'],
            ['account' => $accounts['sale_of_asset'], 'method' => 'credit', 'amount' => 5000, 'memo' => 'Gain on sale of equipment'],
        ]);

        // Transaction 10: Record inventory shrinkage (theft/damage) treated as EXPENSE
        $this->recordTransaction([
            ['account' => $accounts['inventory_shrinkage'], 'method' => 'debit', 'amount' => 1000, 'memo' => 'Inventory loss'],
            ['account' => $accounts['inventory'], 'method' => 'credit', 'amount' => 1000, 'memo' => 'Write off missing inventory'],
        ]);

        // Transaction 11: Provide services on account
        $this->recordTransaction([
            ['account' => $accounts['accounts_receivable'], 'method' => 'debit', 'amount' => 20000, 'memo' => 'Service revenue on account'],
            ['account' => $accounts['service_revenue'], 'method' => 'credit', 'amount' => 20000, 'memo' => 'Service revenue'],
        ]);

        // Transaction 12: Pay accounts payable
        $this->recordTransaction([
            ['account' => $accounts['accounts_payable'], 'method' => 'debit', 'amount' => 40000, 'memo' => 'Pay suppliers'],
            ['account' => $accounts['cash'], 'method' => 'credit', 'amount' => 40000, 'memo' => 'Payment to suppliers'],
        ]);

        // Transaction 13: Collect accounts receivable
        $this->recordTransaction([
            ['account' => $accounts['cash'], 'method' => 'debit', 'amount' => 20000, 'memo' => 'Collect from customers'],
            ['account' => $accounts['accounts_receivable'], 'method' => 'credit', 'amount' => 20000, 'memo' => 'Reduce accounts receivable'],
        ]);

        // ======================
        // 3. Financial Statements
        // ======================

        // Refresh all accounts to get updated balances
        foreach ($accounts as $account) {
            $account->refresh();
        }

        // Assert key account type balances using getCurrentBalance() (in cents).
        // AccountType::getCurrentBalance() already respects debit/credit normal — no change needed.

        // Assets (debit-normal): balance = debits - credits
        $this->assertEquals(13800000, $assetAccountTypes['cash']->getCurrentBalance('USD')->getAmount(), 'Cash balance incorrect');
        $this->assertEquals(2500000, $assetAccountTypes['accounts_receivable']->getCurrentBalance('USD')->getAmount(), 'AR balance incorrect');
        $this->assertEquals(900000, $assetAccountTypes['inventory']->getCurrentBalance('USD')->getAmount(), 'Inventory balance incorrect');
        $this->assertEquals(900000, $assetAccountTypes['equipment']->getCurrentBalance('USD')->getAmount(), 'Equipment balance should be 900000 after accounting for purchase, depreciation, and sale');

        // Liabilities (credit-normal): balance = credits - debits
        $this->assertEquals(0, $liabilityAccountTypes['accounts_payable']->getCurrentBalance('USD')->getAmount(), 'AP should be fully paid');
        $this->assertEquals(5000000, $liabilityAccountTypes['loans_payable']->getCurrentBalance('USD')->getAmount(), 'Loan balance incorrect');

        // Equity (credit-normal): balance = credits - debits
        $this->assertEquals(10000000, $equityAccountTypes['common_stock']->getCurrentBalance('USD')->getAmount(), 'Common stock balance incorrect');

        // Income (credit-normal): balance = credits - debits
        $this->assertEquals(6000000, $incomeAccountTypes['product_sales']->getCurrentBalance('USD')->getAmount(), 'Product sales income incorrect');
        $this->assertEquals(2000000, $incomeAccountTypes['service_revenue']->getCurrentBalance('USD')->getAmount(), 'Service revenue incorrect');
        $this->assertEquals(500000, $incomeAccountTypes['sale_of_asset']->getCurrentBalance('USD')->getAmount(), 'Gain on sale incorrect');

        // Expenses (debit-normal): balance = debits - credits
        $this->assertEquals(3000000, $expenseAccountTypes['cogs']->getCurrentBalance('USD')->getAmount(), 'COGS incorrect');
        $this->assertEquals(1500000, $expenseAccountTypes['salaries']->getCurrentBalance('USD')->getAmount(), 'Salaries expense incorrect');
        $this->assertEquals(500000, $expenseAccountTypes['rent']->getCurrentBalance('USD')->getAmount(), 'Rent expense incorrect');
        $this->assertEquals(200000, $expenseAccountTypes['utilities']->getCurrentBalance('USD')->getAmount(), 'Utilities expense incorrect');
        $this->assertEquals(100000, $expenseAccountTypes['depreciation']->getCurrentBalance('USD')->getAmount(), 'Depreciation expense incorrect');
        $this->assertEquals(100000, $expenseAccountTypes['inventory_shrinkage']->getCurrentBalance('USD')->getAmount(), 'Inventory loss incorrect');

        // Collect totals for accounting equation validation (all in cents)
        $totalAssets =
            $assetAccountTypes['cash']->getCurrentBalance('USD')->getAmount() +
            $assetAccountTypes['accounts_receivable']->getCurrentBalance('USD')->getAmount() +
            $assetAccountTypes['inventory']->getCurrentBalance('USD')->getAmount() +
            $assetAccountTypes['equipment']->getCurrentBalance('USD')->getAmount();

        $totalLiabilities =
            $liabilityAccountTypes['accounts_payable']->getCurrentBalance('USD')->getAmount() +
            $liabilityAccountTypes['loans_payable']->getCurrentBalance('USD')->getAmount();

        $totalEquity =
            $equityAccountTypes['common_stock']->getCurrentBalance('USD')->getAmount() +
            $equityAccountTypes['retained_earnings']->getCurrentBalance('USD')->getAmount();

        // Income now includes both revenue accounts and the gain on sale
        $totalIncome =
            $incomeAccountTypes['product_sales']->getCurrentBalance('USD')->getAmount() +
            $incomeAccountTypes['service_revenue']->getCurrentBalance('USD')->getAmount() +
            $incomeAccountTypes['sale_of_asset']->getCurrentBalance('USD')->getAmount();

        // Expenses now includes inventory shrinkage (formerly a loss)
        $totalExpenses =
            $expenseAccountTypes['cogs']->getCurrentBalance('USD')->getAmount() +
            $expenseAccountTypes['salaries']->getCurrentBalance('USD')->getAmount() +
            $expenseAccountTypes['rent']->getCurrentBalance('USD')->getAmount() +
            $expenseAccountTypes['utilities']->getCurrentBalance('USD')->getAmount() +
            $expenseAccountTypes['depreciation']->getCurrentBalance('USD')->getAmount() +
            $expenseAccountTypes['inventory_shrinkage']->getCurrentBalance('USD')->getAmount();

        $netIncome = $totalIncome - $totalExpenses;

        // Verify net income calculation (in cents)
        // (6,000,000 + 2,000,000 + 500,000) - (3,000,000 + 1,500,000 + 500,000 + 200,000 + 100,000 + 100,000) = 3,100,000
        $this->assertEquals(3100000, $netIncome, 'Net income calculation is incorrect');

        // Close all temporary accounts to retained earnings in one transaction (all amounts in cents).
        // Income accounts are credit-normal: to zero them out, debit them.
        // Expense accounts are debit-normal: to zero them out, credit them.
        // Net effect to retained earnings is a credit for the net income amount.
        $this->recordTransaction([
            // Close income accounts (debit to zero them out, credit-normal)
            ['account' => $accounts['product_sales'], 'method' => 'debit', 'amount' => 6000000, 'memo' => 'Close product sales income'],
            ['account' => $accounts['service_revenue'], 'method' => 'debit', 'amount' => 2000000, 'memo' => 'Close service revenue'],
            ['account' => $accounts['sale_of_asset'], 'method' => 'debit', 'amount' => 500000, 'memo' => 'Close gain on sale'],

            // Close expense accounts (credit to zero them out, debit-normal)
            ['account' => $accounts['cogs'], 'method' => 'credit', 'amount' => 3000000, 'memo' => 'Close COGS'],
            ['account' => $accounts['salaries'], 'method' => 'credit', 'amount' => 1500000, 'memo' => 'Close salaries'],
            ['account' => $accounts['rent'], 'method' => 'credit', 'amount' => 500000, 'memo' => 'Close rent'],
            ['account' => $accounts['utilities'], 'method' => 'credit', 'amount' => 200000, 'memo' => 'Close utilities'],
            ['account' => $accounts['depreciation'], 'method' => 'credit', 'amount' => 100000, 'memo' => 'Close depreciation'],
            ['account' => $accounts['inventory_shrinkage'], 'method' => 'credit', 'amount' => 100000, 'memo' => 'Close inventory loss'],

            // Credit retained earnings for net income
            // (6,000,000 + 2,000,000 + 500,000) - (3,000,000 + 1,500,000 + 500,000 + 200,000 + 100,000 + 100,000) = 3,100,000
            ['account' => $accounts['retained_earnings'], 'method' => 'credit', 'amount' => $netIncome, 'memo' => 'Net income for period'],
        ]);

        // After closing entries, retained_earnings (credit-normal) has a balance of $netIncome
        $retainedEarningsAfterClose = $equityAccountTypes['retained_earnings']->getCurrentBalance('USD')->getAmount();
        $this->assertEquals($netIncome, $retainedEarningsAfterClose, 'Retained earnings should equal net income after closing entries');

        // Verify total equity after closing entries
        $totalEquityAfterClose =
            $equityAccountTypes['common_stock']->getCurrentBalance('USD')->getAmount() +
            $retainedEarningsAfterClose;

        // Verify accounting equation: Assets = Liabilities + Equity
        // After closing entries, all temporary accounts are zeroed and net income is in retained earnings.
        $this->assertEquals(
            $totalAssets,
            $totalLiabilities + $totalEquityAfterClose,
            sprintf(
                'Accounting equation does not balance after closing: Assets (%s) != Liabilities (%s) + Equity (%s)',
                $totalAssets,
                $totalLiabilities,
                $totalEquityAfterClose
            )
        );

        // Verify net income calculation (in cents) one final time
        $expectedNetIncome = 3100000; // (6,000,000 + 2,000,000 + 500,000) - (3,000,000 + 1,500,000 + 500,000 + 200,000 + 100,000 + 100,000)
        $this->assertEquals(
            $expectedNetIncome,
            $netIncome,
            'Net income calculation is incorrect'
        );
    }

    /**
     * Helper method to record a transaction with multiple entries
     */
    private function recordTransaction(array $entries): void
    {
        $transaction = Transaction::newDoubleEntryTransactionGroup();

        foreach ($entries as $entry) {
            $transaction->addTransaction(
                $entry['account'],
                $entry['method'],
                new Money($entry['amount'] * 100, new Currency('USD')),
                $entry['memo'] ?? null,
                null,
                Carbon::now()
            );
        }

        $transaction->commit();
    }
}
