<?php

declare(strict_types=1);

namespace Tests\Unit\Enums;

use Tests\Unit\TestCase;
use App\Accounting\Enums\AccountCategory;

class AccountCategoryTest extends TestCase
{
    public function test_account_category_enum_cases(): void
    {
        $this->assertEquals('asset', AccountCategory::ASSET->value);
        $this->assertEquals('liability', AccountCategory::LIABILITY->value);
        $this->assertEquals('equity', AccountCategory::EQUITY->value);
        $this->assertEquals('income', AccountCategory::INCOME->value);
        $this->assertEquals('expense', AccountCategory::EXPENSE->value);
    }

    public function test_account_category_values_method(): void
    {
        $values = AccountCategory::values();

        $this->assertIsArray($values);
        $this->assertContains('asset', $values);
        $this->assertContains('liability', $values);
        $this->assertContains('equity', $values);
        $this->assertContains('income', $values);
        $this->assertContains('expense', $values);
        $this->assertCount(5, $values);
    }

    public function test_account_category_has_no_gain_or_loss_cases(): void
    {
        $values = AccountCategory::values();

        $this->assertNotContains('gain', $values);
        $this->assertNotContains('loss', $values);
        $this->assertNotContains('revenue', $values);
    }

    public function test_debit_normal_balance_types(): void
    {
        $this->assertTrue(AccountCategory::ASSET->isDebitNormal());
        $this->assertTrue(AccountCategory::EXPENSE->isDebitNormal());

        $this->assertFalse(AccountCategory::LIABILITY->isDebitNormal());
        $this->assertFalse(AccountCategory::EQUITY->isDebitNormal());
        $this->assertFalse(AccountCategory::INCOME->isDebitNormal());
    }

    public function test_credit_normal_balance_types(): void
    {
        $this->assertTrue(AccountCategory::LIABILITY->isCreditNormal());
        $this->assertTrue(AccountCategory::EQUITY->isCreditNormal());
        $this->assertTrue(AccountCategory::INCOME->isCreditNormal());

        $this->assertFalse(AccountCategory::ASSET->isCreditNormal());
        $this->assertFalse(AccountCategory::EXPENSE->isCreditNormal());
    }

    public function test_balance_sign_for_debit_normal_types(): void
    {
        $this->assertEquals(1, AccountCategory::ASSET->balanceSign());
        $this->assertEquals(1, AccountCategory::EXPENSE->balanceSign());
    }

    public function test_balance_sign_for_credit_normal_types(): void
    {
        $this->assertEquals(-1, AccountCategory::LIABILITY->balanceSign());
        $this->assertEquals(-1, AccountCategory::EQUITY->balanceSign());
        $this->assertEquals(-1, AccountCategory::INCOME->balanceSign());
    }

    public function test_is_debit_normal_and_is_credit_normal_are_mutually_exclusive(): void
    {
        foreach (AccountCategory::cases() as $category) {
            $this->assertNotEquals(
                $category->isDebitNormal(),
                $category->isCreditNormal(),
                "Category {$category->value} should be either debit-normal or credit-normal, not both"
            );
        }
    }
}
