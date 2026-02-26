# Project Instructions

> Instructions for Claude Code when working on this project

## Overview

**laravel-accounting** is a Laravel package (`williamlettieri/accounting`) that provides double-entry accounting functionality for any Laravel application. It is not a standalone app -- it is a reusable Composer package installed into consuming Laravel projects.

## Tech Stack

| Layer | Technology | Version |
|-------|-----------|---------|
| PHP | PHP | ^8.2 - ^8.5 |
| Framework | Laravel | ^12.0 |
| Money | moneyphp/money | ^3.3.3 |
| Testing | PHPUnit + Orchestra Testbench | ^11.0 / ^10.0 |
| Container | Docker (PHP 8.5-FPM) | |
| Database (dev) | PostgreSQL 18 | |
| Database (tests) | SQLite in-memory | |

## Package Namespace

Root namespace: `App\Accounting`

| Namespace | Purpose |
|-----------|---------|
| `App\Accounting\Models` | Eloquent models (Account, AccountType, JournalEntry, NonPosting*) |
| `App\Accounting\Services` | Business logic (GeneralJournal, GeneralLedger) |
| `App\Accounting\Enums` | AccountCategory (5 types), NonPostingStatus |
| `App\Accounting\Exceptions` | 8 custom exception classes |
| `App\Accounting\ModelTraits` | HasAccount trait for polymorphic attachment |
| `App\Accounting\Providers` | AccountingServiceProvider (auto-discovery) |

## Key Entry Points

- `Transaction::newDoubleEntryTransactionGroup()` - Build balanced debit/credit transactions
- `Account::debit()` / `Account::credit()` - Raw posting to accounts
- `Account::increase()` / `Account::decrease()` - Smart posting (respects normal balance direction)
- `GeneralJournal::entries()` - Chronological journal report
- `GeneralLedger::forAccount()` - Per-account running balance report
- `NonPostingTransaction::convertToPosting()` - Convert drafts to real entries

## Accounting Principles

- **Double-entry**: Every transaction must have equal debits and credits
- **5 account categories**: Asset, Liability, Equity, Income, Expense
- **Normal balances**: Debit-normal (Asset, Expense) vs Credit-normal (Liability, Equity, Income)
- **Money precision**: All amounts stored as integer cents (bigint) -- never use floats
- **Multi-currency**: ISO 4217 currency codes via moneyphp/money library

## Database

All tables prefixed with `accounting_`:
- `accounting_account_types` - Chart of accounts hierarchy
- `accounting_accounts` - Individual ledger accounts (polymorphic)
- `accounting_journal_entries` - Debit/credit postings (UUID PK, soft deletes)
- `accounting_non_posting_transactions` - Draft transactions (quotes, POs)
- `accounting_non_posting_line_items` - Line items for non-posting transactions

Migrations live in `src/migrations/` and are published via the service provider.

## Testing

```bash
make test              # Run tests with Xdebug coverage
make test-coverage     # Generate HTML coverage report
make open-coverage     # Open report in browser
```

Three test suites in `phpunit.xml`:
- **Unit** (`tests/Unit/`) - Models, services, enums, exceptions, traits
- **Functional** (`tests/Functional/`) - Integration and edge case tests
- **ComplexUseCases** (`tests/ComplexUseCases/`) - Real-world financial scenarios

Base test class (`tests/TestCase.php`) extends `Orchestra\Testbench\TestCase`, loads the service provider, and runs migrations against SQLite in-memory.

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

- All monetary values must use integer cents (bigint) -- never floats or decimals
- Every transaction must balance (total debits = total credits)
- New models must follow existing patterns (UUID keys on journal entries, soft deletes where appropriate)
- Tests are required for all new functionality
- Use Orchestra Testbench patterns for package testing (not `php artisan`)

## Framework Instructions

The Orchestrator framework provides additional commands and workflows:

@.claude/CLAUDE.md
