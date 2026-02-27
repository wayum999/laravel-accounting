<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Accounting\Enums\AccountType;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AccountTypeEnumTest extends TestCase
{
    #[Test]
    public function it_has_five_cases(): void
    {
        $cases = AccountType::cases();
        $this->assertCount(5, $cases);
    }

    #[Test]
    public function it_returns_all_string_values(): void
    {
        $values = AccountType::values();
        $this->assertEquals(['asset', 'liability', 'equity', 'income', 'expense'], $values);
    }

    #[Test]
    public function asset_is_debit_normal(): void
    {
        $this->assertTrue(AccountType::ASSET->isDebitNormal());
        $this->assertFalse(AccountType::ASSET->isCreditNormal());
        $this->assertEquals(1, AccountType::ASSET->balanceSign());
    }

    #[Test]
    public function expense_is_debit_normal(): void
    {
        $this->assertTrue(AccountType::EXPENSE->isDebitNormal());
        $this->assertFalse(AccountType::EXPENSE->isCreditNormal());
        $this->assertEquals(1, AccountType::EXPENSE->balanceSign());
    }

    #[Test]
    public function liability_is_credit_normal(): void
    {
        $this->assertFalse(AccountType::LIABILITY->isDebitNormal());
        $this->assertTrue(AccountType::LIABILITY->isCreditNormal());
        $this->assertEquals(-1, AccountType::LIABILITY->balanceSign());
    }

    #[Test]
    public function equity_is_credit_normal(): void
    {
        $this->assertFalse(AccountType::EQUITY->isDebitNormal());
        $this->assertTrue(AccountType::EQUITY->isCreditNormal());
        $this->assertEquals(-1, AccountType::EQUITY->balanceSign());
    }

    #[Test]
    public function income_is_credit_normal(): void
    {
        $this->assertFalse(AccountType::INCOME->isDebitNormal());
        $this->assertTrue(AccountType::INCOME->isCreditNormal());
        $this->assertEquals(-1, AccountType::INCOME->balanceSign());
    }

    #[Test]
    public function it_returns_human_readable_labels(): void
    {
        $this->assertEquals('Asset', AccountType::ASSET->label());
        $this->assertEquals('Liability', AccountType::LIABILITY->label());
        $this->assertEquals('Equity', AccountType::EQUITY->label());
        $this->assertEquals('Income', AccountType::INCOME->label());
        $this->assertEquals('Expense', AccountType::EXPENSE->label());
    }

    #[Test]
    public function it_can_be_created_from_string_value(): void
    {
        $this->assertEquals(AccountType::ASSET, AccountType::from('asset'));
        $this->assertEquals(AccountType::LIABILITY, AccountType::from('liability'));
        $this->assertEquals(AccountType::EQUITY, AccountType::from('equity'));
        $this->assertEquals(AccountType::INCOME, AccountType::from('income'));
        $this->assertEquals(AccountType::EXPENSE, AccountType::from('expense'));
    }
}
