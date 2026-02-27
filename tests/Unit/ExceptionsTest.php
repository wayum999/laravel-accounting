<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Accounting\Exceptions\AccountAlreadyExistsException;
use App\Accounting\Exceptions\InvalidAmountException;
use App\Accounting\Exceptions\InvalidEntryMethodException;
use App\Accounting\Exceptions\UnbalancedTransactionException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ExceptionsTest extends TestCase
{
    #[Test]
    public function unbalanced_transaction_exception_contains_amounts(): void
    {
        $e = new UnbalancedTransactionException(5000, 3000);

        $this->assertStringContainsString('5000', $e->getMessage());
        $this->assertStringContainsString('3000', $e->getMessage());
    }

    #[Test]
    public function invalid_entry_method_exception_contains_method(): void
    {
        $e = new InvalidEntryMethodException('transfer');

        $this->assertStringContainsString('transfer', $e->getMessage());
    }

    #[Test]
    public function invalid_amount_exception_is_throwable(): void
    {
        $this->expectException(InvalidAmountException::class);
        throw new InvalidAmountException();
    }

    #[Test]
    public function account_already_exists_exception_is_throwable(): void
    {
        $this->expectException(AccountAlreadyExistsException::class);
        throw new AccountAlreadyExistsException('Cash account already exists');
    }
}
