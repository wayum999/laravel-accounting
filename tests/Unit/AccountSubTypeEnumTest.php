<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Accounting\Enums\AccountSubType;
use App\Accounting\Enums\AccountType;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AccountSubTypeEnumTest extends TestCase
{
    #[Test]
    public function asset_sub_types_have_correct_parent(): void
    {
        $assetSubTypes = [
            AccountSubType::BANK,
            AccountSubType::ACCOUNTS_RECEIVABLE,
            AccountSubType::OTHER_CURRENT_ASSET,
            AccountSubType::INVENTORY,
            AccountSubType::FIXED_ASSET,
            AccountSubType::OTHER_ASSET,
        ];

        foreach ($assetSubTypes as $subType) {
            $this->assertEquals(AccountType::ASSET, $subType->parentType(), "{$subType->value} should be an ASSET sub-type");
        }
    }

    #[Test]
    public function liability_sub_types_have_correct_parent(): void
    {
        $liabilitySubTypes = [
            AccountSubType::ACCOUNTS_PAYABLE,
            AccountSubType::CREDIT_CARD,
            AccountSubType::OTHER_CURRENT_LIABILITY,
            AccountSubType::LONG_TERM_LIABILITY,
        ];

        foreach ($liabilitySubTypes as $subType) {
            $this->assertEquals(AccountType::LIABILITY, $subType->parentType(), "{$subType->value} should be a LIABILITY sub-type");
        }
    }

    #[Test]
    public function equity_sub_types_have_correct_parent(): void
    {
        $this->assertEquals(AccountType::EQUITY, AccountSubType::OWNERS_EQUITY->parentType());
        $this->assertEquals(AccountType::EQUITY, AccountSubType::RETAINED_EARNINGS->parentType());
    }

    #[Test]
    public function revenue_sub_types_have_correct_parent(): void
    {
        $this->assertEquals(AccountType::REVENUE, AccountSubType::REVENUE->parentType());
        $this->assertEquals(AccountType::REVENUE, AccountSubType::SALES_DISCOUNTS->parentType());
        $this->assertEquals(AccountType::REVENUE, AccountSubType::SALES_RETURNS_ALLOWANCES->parentType());
    }

    #[Test]
    public function other_income_sub_types_have_correct_parent(): void
    {
        $this->assertEquals(AccountType::OTHER_INCOME, AccountSubType::OTHER_INCOME->parentType());
        $this->assertEquals(AccountType::OTHER_INCOME, AccountSubType::GAIN_ON_SALE->parentType());
        $this->assertEquals(AccountType::OTHER_INCOME, AccountSubType::OTHER_GAIN->parentType());
    }

    #[Test]
    public function expense_sub_types_have_correct_parent(): void
    {
        $this->assertEquals(AccountType::EXPENSE, AccountSubType::COST_OF_GOODS_SOLD->parentType());
        $this->assertEquals(AccountType::EXPENSE, AccountSubType::OPERATING_EXPENSE->parentType());
    }

    #[Test]
    public function other_expense_sub_types_have_correct_parent(): void
    {
        $this->assertEquals(AccountType::OTHER_EXPENSE, AccountSubType::OTHER_EXPENSE->parentType());
        $this->assertEquals(AccountType::OTHER_EXPENSE, AccountSubType::LOSS_ON_SALE->parentType());
        $this->assertEquals(AccountType::OTHER_EXPENSE, AccountSubType::OTHER_LOSS->parentType());
    }

    #[Test]
    public function report_group_returns_correct_sections(): void
    {
        $this->assertEquals('Current Assets', AccountSubType::BANK->reportGroup());
        $this->assertEquals('Current Assets', AccountSubType::ACCOUNTS_RECEIVABLE->reportGroup());
        $this->assertEquals('Current Assets', AccountSubType::INVENTORY->reportGroup());
        $this->assertEquals('Fixed Assets', AccountSubType::FIXED_ASSET->reportGroup());
        $this->assertEquals('Other Assets', AccountSubType::OTHER_ASSET->reportGroup());
        $this->assertEquals('Current Liabilities', AccountSubType::ACCOUNTS_PAYABLE->reportGroup());
        $this->assertEquals('Long-Term Liabilities', AccountSubType::LONG_TERM_LIABILITY->reportGroup());
        $this->assertEquals('Equity', AccountSubType::OWNERS_EQUITY->reportGroup());
        $this->assertEquals('Revenue', AccountSubType::REVENUE->reportGroup());
        $this->assertEquals('Revenue', AccountSubType::SALES_DISCOUNTS->reportGroup());
        $this->assertEquals('Revenue', AccountSubType::SALES_RETURNS_ALLOWANCES->reportGroup());
        $this->assertEquals('Other Income', AccountSubType::OTHER_INCOME->reportGroup());
        $this->assertEquals('Other Income', AccountSubType::GAIN_ON_SALE->reportGroup());
        $this->assertEquals('Other Income', AccountSubType::OTHER_GAIN->reportGroup());
        $this->assertEquals('Cost of Goods Sold', AccountSubType::COST_OF_GOODS_SOLD->reportGroup());
        $this->assertEquals('Operating Expenses', AccountSubType::OPERATING_EXPENSE->reportGroup());
        $this->assertEquals('Other Expenses', AccountSubType::OTHER_EXPENSE->reportGroup());
        $this->assertEquals('Other Expenses', AccountSubType::LOSS_ON_SALE->reportGroup());
        $this->assertEquals('Other Expenses', AccountSubType::OTHER_LOSS->reportGroup());
    }

    #[Test]
    public function is_current_identifies_short_term_items(): void
    {
        // Current items
        $this->assertTrue(AccountSubType::BANK->isCurrent());
        $this->assertTrue(AccountSubType::ACCOUNTS_RECEIVABLE->isCurrent());
        $this->assertTrue(AccountSubType::OTHER_CURRENT_ASSET->isCurrent());
        $this->assertTrue(AccountSubType::INVENTORY->isCurrent());
        $this->assertTrue(AccountSubType::ACCOUNTS_PAYABLE->isCurrent());
        $this->assertTrue(AccountSubType::CREDIT_CARD->isCurrent());
        $this->assertTrue(AccountSubType::OTHER_CURRENT_LIABILITY->isCurrent());

        // Non-current items
        $this->assertFalse(AccountSubType::FIXED_ASSET->isCurrent());
        $this->assertFalse(AccountSubType::OTHER_ASSET->isCurrent());
        $this->assertFalse(AccountSubType::LONG_TERM_LIABILITY->isCurrent());
        $this->assertFalse(AccountSubType::OWNERS_EQUITY->isCurrent());
        $this->assertFalse(AccountSubType::RETAINED_EARNINGS->isCurrent());
    }

    #[Test]
    public function label_returns_human_readable_names(): void
    {
        $this->assertEquals('Bank', AccountSubType::BANK->label());
        $this->assertEquals('Accounts Receivable', AccountSubType::ACCOUNTS_RECEIVABLE->label());
        $this->assertEquals('Cost of Goods Sold', AccountSubType::COST_OF_GOODS_SOLD->label());
        $this->assertEquals("Owner's Equity", AccountSubType::OWNERS_EQUITY->label());
        $this->assertEquals('Long-Term Liability', AccountSubType::LONG_TERM_LIABILITY->label());
        $this->assertEquals('Sales Discounts', AccountSubType::SALES_DISCOUNTS->label());
        $this->assertEquals('Sales Returns & Allowances', AccountSubType::SALES_RETURNS_ALLOWANCES->label());
        $this->assertEquals('Gain on Sale', AccountSubType::GAIN_ON_SALE->label());
        $this->assertEquals('Loss on Sale', AccountSubType::LOSS_ON_SALE->label());
    }

    #[Test]
    public function for_type_returns_correct_sub_types(): void
    {
        $assetSubTypes = AccountSubType::forType(AccountType::ASSET);
        $this->assertCount(6, $assetSubTypes);

        $liabilitySubTypes = AccountSubType::forType(AccountType::LIABILITY);
        $this->assertCount(4, $liabilitySubTypes);

        $equitySubTypes = AccountSubType::forType(AccountType::EQUITY);
        $this->assertCount(2, $equitySubTypes);

        $revenueSubTypes = AccountSubType::forType(AccountType::REVENUE);
        $this->assertCount(3, $revenueSubTypes);

        $otherIncomeSubTypes = AccountSubType::forType(AccountType::OTHER_INCOME);
        $this->assertCount(3, $otherIncomeSubTypes);

        $expenseSubTypes = AccountSubType::forType(AccountType::EXPENSE);
        $this->assertCount(2, $expenseSubTypes);

        $otherExpenseSubTypes = AccountSubType::forType(AccountType::OTHER_EXPENSE);
        $this->assertCount(3, $otherExpenseSubTypes);
    }

    #[Test]
    public function total_cases_is_twenty_three(): void
    {
        $this->assertCount(23, AccountSubType::cases());
    }
}
