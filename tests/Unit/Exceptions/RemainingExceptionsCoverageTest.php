<?php

declare(strict_types=1);

namespace Tests\Unit\Exceptions;

use App\Accounting\Exceptions\InvalidJournalEntryValue;
use App\Accounting\Exceptions\InvalidJournalMethod;
use App\Accounting\Exceptions\AccountAlreadyExists;
use Tests\Unit\TestCase;

class RemainingExceptionsCoverageTest extends TestCase
{
    public function test_invalid_journal_entry_value_exception_instantiation(): void
    {
        // Test direct instantiation of InvalidJournalEntryValue
        $exception = new InvalidJournalEntryValue();

        $this->assertInstanceOf(\App\Accounting\Exceptions\BaseException::class, $exception);
        $this->assertEquals('Journal entry values must be a positive value', $exception->getMessage());
    }

    public function test_invalid_journal_method_exception_instantiation(): void
    {
        // Test direct instantiation of InvalidJournalMethod
        $exception = new InvalidJournalMethod();

        $this->assertInstanceOf(\App\Accounting\Exceptions\BaseException::class, $exception);
        $this->assertEquals('Journal methods must be credit or debit', $exception->getMessage());
    }

    public function test_account_already_exists_exception_instantiation(): void
    {
        // Test direct instantiation of AccountAlreadyExists
        $exception = new AccountAlreadyExists();

        $this->assertInstanceOf(\App\Accounting\Exceptions\BaseException::class, $exception);
        $this->assertEquals('Account already exists.', $exception->getMessage());
    }

    public function test_all_exception_classes_exist(): void
    {
        // Ensure all exception classes can be instantiated
        $exceptions = [
            new InvalidJournalEntryValue(),
            new InvalidJournalMethod(),
            new AccountAlreadyExists(),
        ];

        foreach ($exceptions as $exception) {
            $this->assertInstanceOf(\Exception::class, $exception);
            $this->assertNotEmpty($exception->getMessage());
        }
    }
}
