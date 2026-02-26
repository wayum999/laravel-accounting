# Project Instructions

> Instructions for Gemini when working on this project

## Overview

**laravel-accounting** is a Laravel package (`williamlettieri/accounting`) that provides full double-entry accounting functionality for any Laravel application. It is **not** a standalone app — it is a reusable Composer package installed into consuming Laravel projects via Packagist.

## Tech Stack

| Layer            | Technology                    | Version       |
| ---------------- | ----------------------------- | ------------- |
| PHP              | PHP                           | ^8.2 – ^8.5   |
| Framework        | Laravel                       | ^12.0         |
| Money            | moneyphp/money                | ^3.3.3        |
| Testing          | PHPUnit + Orchestra Testbench | ^11.0 / ^10.0 |
| Container        | Docker (PHP 8.5-FPM)          |               |
| Database (dev)   | PostgreSQL 18                 |               |
| Database (tests) | SQLite in-memory              |               |

## Package Namespace

Root namespace: `App\Accounting`

| Namespace                                  | Purpose                                                                                      |
| ------------------------------------------ | -------------------------------------------------------------------------------------------- |
| `App\Accounting\Models`                    | Eloquent models (Account, AccountType, JournalEntry, NonPosting\*, AuditEntry, FiscalPeriod) |
| `App\Accounting\Services`                  | Business logic (GeneralJournal, GeneralLedger, ChartOfAccountsSeeder)                        |
| `App\Accounting\Services\FinancialReports` | Report generators (BalanceSheet, IncomeStatement, TrialBalance, AgingReport)                 |
| `App\Accounting\Enums`                     | AccountCategory, NonPostingStatus, FiscalPeriodStatus                                        |
| `App\Accounting\Exceptions`                | 11 custom exception classes (all extend BaseException)                                       |
| `App\Accounting\ModelTraits`               | HasAccount, HasAuditLog, HasReferencedObject                                                 |
| `App\Accounting\Providers`                 | AccountingServiceProvider (auto-discovery)                                                   |

## Key Entry Points

- `Transaction::newDoubleEntryTransactionGroup()` — Build balanced debit/credit transactions
- `Transaction::reverseGroup()` — Reverse an entire transaction group
- `Transaction::voidGroup()` — Void a transaction group (reverse with original dates)
- `Account::debit()` / `Account::credit()` — Raw posting to accounts
- `Account::increase()` / `Account::decrease()` — Smart posting (respects normal balance direction)
- `GeneralJournal::entries()` — Chronological journal report
- `GeneralLedger::forAccount()` — Per-account running balance report
- `NonPostingTransaction::convertToPosting()` — Convert drafts to real entries
- `FiscalPeriod::close()` / `FiscalPeriod::reopen()` — Period management with closing entries
- `BalanceSheet::generate()` — Point-in-time balance sheet
- `IncomeStatement::generate()` — Period income statement (P&L)
- `TrialBalance::generate()` — Trial balance report
- `AgingReport::receivables()` / `AgingReport::payables()` — AR/AP aging

## Accounting Principles

- **Double-entry**: Every transaction must have equal debits and credits
- **5 account categories**: Asset, Liability, Equity, Income, Expense
- **Normal balances**: Debit-normal (Asset, Expense) vs Credit-normal (Liability, Equity, Income)
- **Money precision**: All amounts stored as integer cents (bigint) — **never use floats**
- **Multi-currency**: ISO 4217 currency codes via moneyphp/money library
- **Positive balances**: `Account::getBalance()` always returns a positive value when the account is in its normal balance state
- **Fiscal periods**: Support for period closing with automatic closing entries
- **Audit trail**: Optional audit logging via `HasAuditLog` trait

## Database

All tables are prefixed with `accounting_`:

| Table                                 | Purpose                                                     |
| ------------------------------------- | ----------------------------------------------------------- |
| `accounting_account_types`            | Chart of accounts hierarchy (supports parent/child nesting) |
| `accounting_accounts`                 | Individual ledger accounts (polymorphic)                    |
| `accounting_journal_entries`          | Debit/credit postings (UUID PK, soft deletes)               |
| `accounting_non_posting_transactions` | Draft transactions (quotes, POs, estimates)                 |
| `accounting_non_posting_line_items`   | Line items for non-posting transactions                     |
| `accounting_fiscal_periods`           | Fiscal period tracking with close/reopen                    |
| `accounting_audit_entries`            | Immutable audit log entries                                 |

Migrations live in `src/migrations/` and are published via the service provider.

## Source Structure

```
src/
├── Enums/             # AccountCategory, NonPostingStatus, FiscalPeriodStatus
├── Exceptions/        # 11 exceptions + BaseException
├── ModelTraits/       # HasAccount, HasAuditLog, HasReferencedObject
├── Models/            # Account, AccountType, JournalEntry, NonPosting*, AuditEntry, FiscalPeriod
├── Providers/         # AccountingServiceProvider
├── Services/          # GeneralJournal, GeneralLedger, ChartOfAccountsSeeder
│   └── FinancialReports/  # BalanceSheet, IncomeStatement, TrialBalance, AgingReport
├── Transaction.php    # Double-entry transaction builder
└── migrations/        # 8 published migration files
```

## Testing

```bash
make test              # Run tests with Xdebug coverage
make test-coverage     # Generate HTML coverage report
make open-coverage     # Open report in browser
```

Three test suites defined in `phpunit.xml`:

| Suite               | Location                 | Purpose                                     |
| ------------------- | ------------------------ | ------------------------------------------- |
| **Unit**            | `tests/Unit/`            | Models, services, enums, exceptions, traits |
| **Functional**      | `tests/Functional/`      | Integration and edge cases                  |
| **ComplexUseCases** | `tests/ComplexUseCases/` | Real-world financial scenarios              |

Base test class (`tests/TestCase.php`) extends `Orchestra\Testbench\TestCase`, loads the service provider, and runs migrations against SQLite in-memory.

> **Do not use `php artisan` in tests.** Use Orchestra Testbench patterns exclusively.

## Docker

```bash
make up       # Start containers (app + PostgreSQL)
make down     # Stop containers
make build    # Rebuild PHP image
make ssh      # Shell into app container
make install  # composer install
make update   # composer update
```

## Development Rules

1. **Integer cents only** — All monetary values must use bigint cents; never floats or decimals
2. **Balanced transactions** — Every `Transaction::commit()` must have equal total debits and credits
3. **UUID keys on journal entries** — Follow existing model patterns (UUID PKs with `$keyType = 'string'`, `$incrementing = false`, soft deletes where present)
4. **`$guarded = []`** — All models use `$guarded = []` (modern Laravel convention); never use `$fillable`
5. **Tests required** — All new functionality must be covered by tests
6. **Package patterns** — Use Orchestra Testbench; this is a Composer package, not a standalone app
7. **No time estimates** — Use task count, file scope, or complexity bands (S/M/L/XL) instead
8. **Use enums for status** — `AccountCategory`, `NonPostingStatus`, `FiscalPeriodStatus` — never use string literals for status values
9. **Use traits for shared behavior** — `HasReferencedObject` for polymorphic references, `HasAuditLog` for audit trails, `HasAccount` for model-to-account attachment

## Exceptions Reference

| Exception                             | Thrown When                                                                       |
| ------------------------------------- | --------------------------------------------------------------------------------- |
| `AccountAlreadyExists`                | `initAccount()` called on a model that already has an account                     |
| `DebitsAndCreditsDoNotEqual`          | `Transaction::commit()` called with unbalanced entries                            |
| `InvalidJournalEntryValue`            | Entry amount of zero or less added to a `Transaction`                             |
| `InvalidJournalMethod`                | Method other than `'debit'` or `'credit'` passed to `addTransaction()`            |
| `TransactionCouldNotBeProcessed`      | Database error during `Transaction::commit()`                                     |
| `TransactionAlreadyReversedException` | Attempting to reverse an already-reversed entry or group                          |
| `NonPostingAlreadyConverted`          | `convertToPosting()` called on an already-converted or invalid-status transaction |
| `InvalidAccountCategory`              | Invalid category value used                                                       |
| `FiscalPeriodOverlapException`        | Creating a fiscal period that overlaps with an existing one                       |
| `PeriodClosedException`               | Posting to a date within a closed fiscal period                                   |

All exceptions extend `App\Accounting\Exceptions\BaseException`.

## Orchestrator Framework

This project uses the **Orchestrator** framework (v16.3.0) for agentic workflows. Key commands:

```bash
/core:prd:new feature-name      # Create a requirements doc
/core:prd:parse feature-name    # Generate implementation epic
/core:epic:start feature-name   # Start working on an epic
/core:epic:auto feature-name    # Fully autonomous epic execution
/core:epic:close feature-name   # Complete an epic
```

Workflow artifacts live in `.orchestrator/` (PRDs, epics, ideas). Framework files live in `.claude/`.

> See `.claude/CLAUDE.md` for the full Orchestrator reference.
