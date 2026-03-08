# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [2.0.0] - Unreleased

### BREAKING CHANGES
- **`AccountType::INCOME` renamed to `AccountType::REVENUE`** — any consumer code
  referencing `AccountType::INCOME` must be updated to `AccountType::REVENUE`.
  The database column value changed from `'income'` to `'revenue'`; migration
  `2026_03_06_000008` handles existing rows automatically.
- **New account types `OTHER_INCOME` and `OTHER_EXPENSE`** — non-operating income/expense
  accounts are now separate types (previously there was only `INCOME` and `EXPENSE`).
  Migration `2026_03_06_000009` reclassifies existing `gain`/`loss` rows and
  `sub_type=other_expense` accounts.

### Fixed
- **Critical: TOCTOU race condition in `LedgerEntry::creating` hook** — `running_balance`
  is now initialised to `0` on creation and recomputed in a sequential, row-locked pass
  via `Account::resequenceRunningBalances()` after each batch of posts/creates.
- **Critical: `JournalEntry::post()` stale running balance for multi-line same-account entries** —
  all ledger entries are now bulk-set to `is_posted = true` before the resequencing pass,
  ensuring entries already part of the same journal are visible to the balance query.
- **High: Missing `DB::transaction()` wrappers** — `post()`, `unpost()`, `reverse()`, and
  `void()` are all now wrapped in database transactions, preventing partial-commit states
  on mid-operation failures.
- **High: `reverse()` balance recalculation for reversal-only accounts** — both the original
  and reversal entry account IDs are collected and resequenced, not just the original side.
- **High: N+1 queries in `post()`, `reverse()`, `void()`** — accounts are now eager-loaded
  before iteration loops.
- **High: Redundant pluck query after iteration** — `account_id` values are now collected
  from the in-memory collection instead of a second database query.
- **High: N+1 queries in `BalanceSheet`, `IncomeStatement`, `TrialBalance`** — replaced
  per-account `SUM` loop with a single `GROUP BY` aggregate query per report type.
- **Medium: `Account::debit()` / `credit()` not wrapped in transactions** — each call is
  now atomic.
- **Medium: `isDebitNormal()` silent default for null account type** — now throws
  `\LogicException` instead of silently returning `true`.
- **Medium: `Account::getBalance()` issued two separate queries** — consolidated to a
  single `selectRaw` with `SUM` for both debit and credit.
- **Medium: Morph map not enforced** — `Relation::enforceMorphMap()` is now configured in
  `AccountingServiceProvider::boot()`.
- **Medium: `cached_balance` in `Account::$fillable`** — removed to prevent external
  manipulation of the reported balance.
- **Medium: Null `sub_type` silently promoted to operating expenses** — routed to an
  explicit `uncategorised_expenses` bucket in `IncomeStatement`.
- **Medium: Future-dated entries incorrectly bucketed in `AgingReport`** — `diffInDays`
  now uses a signed comparison; negative (future) entries are excluded from all buckets.
- **Medium: `CashFlowStatement` loaded full nested graph** — replaced
  `with('journalEntry.ledgerEntries.account')` with a selective JOIN that fetches only
  the contra-account type.
- **Low: `CashFlowStatement::match()` had no default arm** — unknown contra types now
  default to `operating` instead of throwing `UnhandledMatchError`.
- **Low: `LedgerEntry::referencesModel()` deprecated dead code** — removed.
- **Low: `Account::getCurrentBalance()` / `getCurrentBalanceInDollars()`** — marked
  `@deprecated`; use `getBalance()` / `getBalanceInDollars()` instead.
- **Low: Redundant `$this->refresh()` after `debit()` / `credit()`** — removed.
- **Low: `empty()` used for UUID check in `JournalEntry::boot`** — replaced with strict
  `!isset($entry->id) || $entry->id === ''` check.
- **Low: Hardcoded `'USD'` string literals** — replaced with
  `config('accounting.base_currency', 'USD')`.
- **Low: Unused `audit` configuration block** — removed from `config/accounting.php`.
- **Low: Docker Compose hardcoded credentials** — replaced with environment variable
  interpolation; credentials must now be supplied via `.env`.
- **Low: Root `.env` not in `.gitignore`** — added `.env` / `.env.*` exclusions.
- **Low: Unused `mockery/mockery` and `fakerphp/faker` dev dependencies** — removed.
- **Low: Incorrect `allow-plugins` entries** — `phpunit/phpunit` and
  `orchestra/testbench` are not Composer plugins; removed from `allow-plugins`.
- **Low: `minimum-stability: dev`** — changed to `stable`.

### Added
- `Account::resequenceRunningBalances()` — row-locked sequential pass to recompute
  `running_balance` on all posted ledger entries for an account.
- `BalanceSheet::generate()` now accepts a `$periodStart` parameter for non-calendar
  fiscal years.
- `IncomeStatement` now exposes an `uncategorised_expenses` key for expense accounts
  with a null `sub_type`.
- Migration `2026_03_01_000007` — composite index on `accounting_accounts(type, is_active)`
  and unique constraint on `accounting_accounts(code)`.
- `.env.example` documenting all required environment variables.
- `TransactionBuilder` class docblock.
- `LedgerEntry` class docblock explaining immutability contract and `running_balance` lifecycle.
- `Account` class docblock clarifying the `balance` vs `getBalance()` distinction.
- `JournalEntry` class docblock documenting UUID primary key and posting lifecycle.
