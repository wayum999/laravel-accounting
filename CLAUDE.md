# Project Instructions

> Instructions for Claude Code when working on this project

## Overview

**Laravel Accounting** is a double-entry accounting package for Laravel 12.x (PHP 8.2+). It provides immutable ledger entries, running balances, draft transaction support, and financial reporting.

### Key Architecture

- **Double-entry bookkeeping**: Every transaction must have balanced debits and credits
- **Immutable ledger**: Posted journal entries cannot be modified, only reversed or voided
- **Chart of Accounts**: Hierarchical account structure with account types (Asset, Liability, Equity, Revenue, Expense)
- **Draft support**: Transactions can be created as drafts before posting

### Project Structure

```
src/
  Enums/          # Account types, transaction states, balance types
  Exceptions/     # Domain-specific exceptions
  Models/         # Eloquent models (Account, JournalEntry, Transaction, etc.)
  Providers/      # Service providers
  Services/       # Business logic services
  Traits/         # Reusable model traits
  migrations/     # Database migrations
config/           # Package configuration
docker/           # Docker setup for development
tests/            # Test suite
```

### Development Standards

- Follow Laravel package conventions
- Maintain immutability guarantees on posted entries
- All transactions must balance (debits = credits)
- Use proper money handling (avoid floating point)
- Write tests for all accounting logic
- Run tests: `make test` or `./vendor/bin/phpunit`

## Framework Instructions

The Orchestrator framework provides additional commands and workflows:

@.claude/CLAUDE.md
