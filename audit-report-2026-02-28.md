# Codebase Audit Report

**Generated:** 2026-02-28
**Agents Run:** 8 specialist agents + synthesizer
**Total Raw Findings:** 97 (before deduplication)
**Unique Findings After Deduplication:** 72

---

## Executive Summary

| Severity | Count |
|----------|-------|
| Critical | 6 |
| High     | 26 |
| Medium   | 26 |
| Low      | 14 |
| **Total**| **72** |

The most urgent risk in this codebase is **data corruption in the running balance ledger**: two independent race conditions (one in `LedgerEntry::boot()` creating hook and one in `JournalEntry::post()`) will silently produce incorrect running balances under concurrent writes or multi-line journal entries. Compounding this, `reverse()`, `void()`, `post()`, and `unpost()` are all missing database transaction wrappers, meaning any mid-operation failure leaves the books in a partially committed, inconsistent state. The second major risk area is **security**: dynamic class instantiation from a user-fillable database column (`ref_class` in `accounting-master`) and mass-assignable polymorphic type columns across three models create exploitable attack surface. The third systemic issue is **N+1 query proliferation** across all five report generators — a balance sheet with 40 accounts currently issues over 120 individual SQL aggregate queries per request.

---

## Findings by Category

### Data Integrity / Race Conditions

| File | Line | Severity | Description |
|------|------|----------|-------------|
| `src/Models/LedgerEntry.php` | 56 | Critical | TOCTOU race in `creating` boot hook: concurrent inserts for the same account read the same `lastBalance` and write identical `running_balance` |
| `src/Models/JournalEntry.php` | 100 | Critical | `post()` queries last-posted entry before marking current entries posted; multi-line entries to the same account all compute off the same stale starting balance |
| `src/Models/JournalEntry.php` | 97 | High | `post()` not wrapped in a DB transaction; partial post leaves some entries posted and balance recalculation skipped |
| `src/Models/JournalEntry.php` | 139 | High | `unpost()` not wrapped in a DB transaction; failure mid-loop leaves partially unposted state |
| `src/Models/JournalEntry.php` | 178 | High | `reverse()` not wrapped in a DB transaction; partial reversal leaves books inconsistent |
| `src/Models/JournalEntry.php` | 200 | High | `void()` not wrapped in a DB transaction; same partial-commit risk as `reverse()` |
| `src/Models/JournalEntry.php` | 169 | High | `reverse()` does not recalculate balances for accounts only referenced in the reversal entry, not the original |
| `src/Models/Account.php` | 213 | Medium | `debit()`/`credit()` create standalone entries with no journal (bypassing double-entry invariant) without a DB transaction |
| `src/Services/ChartOfAccountsSeeder.php` | 53 | Medium | TOCTOU check-then-create for `account.code`; no unique index on `code` column |

### Security

| File | Line | Severity | Description |
|------|------|----------|-------------|
| `accounting-master/src/Models/JournalTransaction.php` | 74 | High | Dynamic class instantiation from database column `ref_class` using `new $this->ref_class` (CWE-470) |
| `accounting-master/src/Models/JournalTransaction.php` | 27 | High | `ref_class` and `ref_class_id` are in `$fillable`, creating a mass-assignment path to the unsafe instantiation (CWE-915) |
| `src/Models/Account.php` | 34 | Medium | `accountable_type` and `accountable_id` are mass-assignable; no `Relation::enforceMorphMap()` configured (CWE-915) |
| `src/Models/LedgerEntry.php` | 27 | Medium | `ledgerable_type` and `ledgerable_id` are mass-assignable with no morph map enforcement (CWE-915) |
| `src/Models/Account.php` | 24 | Medium | `cached_balance` is in `$fillable`, allowing external manipulation of the reported account balance (CWE-915) |
| `src/Providers/AccountingServiceProvider.php` | 1 | Medium | No `Relation::morphMap()` or `Relation::enforceMorphMap()` configured; full class names stored in DB expose namespace structure (CWE-668) |
| `docker-compose.yml` | 19 | Medium | Hardcoded credentials committed to version control: `DB_PASSWORD=password` (CWE-798) |
| `accounting-master/docker-compose.yml` | 27 | Medium | Hardcoded MySQL credentials committed to version control: `MYSQL_ROOT_PASSWORD=secret` (CWE-798) |
| `docker-compose.yml` | 12 | Low | `APP_DEBUG=true` in compose file; could expose stack traces if used outside development (CWE-209) |
| `accounting-master/docker-compose.yml` | 12 | Low | `APP_DEBUG=true` in accounting-master compose file (CWE-209) |
| `docker-compose.yml` | 13 | Low | `APP_KEY` set to empty string; Laravel encryption effectively disabled (CWE-326) |
| `.gitignore` | 1 | Low | Root-level `.env` not in `.gitignore`; post-install script creates `.env` from `.env.example` which could be accidentally committed (CWE-538) |
| `docker/php/xdebug.ini` | 3 | Low | Xdebug enabled with `start_with_request=yes`; if image deployed to production, leaks internal state (CWE-489) |
| `accounting-master/src/migrations/...transactions_table.php` | 28 | Low | Foreign key constraints commented out "for flexibility in testing", weakening referential integrity (CWE-1285) |
| `accounting-master/src/Models/JournalTransaction.php` | 22 | Low | `ref_class` column limited to 64 characters; many namespaced class names exceed this, causing silent truncation |
| `src/Services/TransactionBuilder.php` | 36 | Low | `Carbon::parse($date)` accepts unexpected formats; no range or format validation on transaction dates (CWE-20) |
| `src/Models/Account.php` | 208 | Low | `debit()`/`credit()` do not validate amount > 0; negative amounts are semantically equivalent to the opposite operation |

### Performance / N+1 Queries

| File | Line | Severity | Description |
|------|------|----------|-------------|
| `src/Services/Reports/BalanceSheet.php` | 86 | Critical | 2N queries per account type inside `getAccountBalances()` foreach; 3 calls per balance sheet + IncomeStatement delegation = 120+ queries for 40 accounts |
| `src/Services/Reports/IncomeStatement.php` | 40 | Critical | 2 queries per income/expense account in foreach loops; ~60 queries for a typical chart; called a second time by BalanceSheet |
| `src/Services/Reports/TrialBalance.php` | 32 | Critical | 2N aggregate queries inside foreach; 100 queries for 50 accounts |
| `src/Models/LedgerEntry.php` | 56 | High | `creating` boot hook fires 2 extra queries per entry (last-balance lookup + account find); 4 extra queries per commit for a 2-line journal entry |
| `src/Models/JournalEntry.php` | 97 | High | `post()` loop: lazy-loads account per entry + 1 running-balance query per entry = 2N+1 queries; separate `pluck('account_id')` after already having the data |
| `src/Models/JournalEntry.php` | 178 | High | `reverse()` and `void()` lazy-load `$entry->account` per entry in the foreach loop = N separate SELECTs |
| `src/Services/Reports/AgingReport.php` | 68 | High | Loads all ledger entries for each account into PHP memory with no pagination; O(total entries) memory usage |
| `src/Services/ChartOfAccountsSeeder.php` | 53 | High | 1 SELECT per account code in idempotent seeder; 40 queries for the retail template |
| `src/Models/Account.php` | 134 | High | `getBalance()` issues two separate SUM queries with identical WHERE clauses instead of one `selectRaw` |
| `src/Models/Account.php` | 162 | High | `getBalanceOn()` constructs two separate queries and calls `endOfDay()` twice |
| `src/Models/LedgerEntry.php` | 75 | High | `created` event calls `recalculateBalance()` per entry; `TransactionBuilder::commit()` also recalculates per account after the loop — double recalculation per account per commit |
| `src/Services/Reports/CashFlowStatement.php` | 36 | High | Eager-loads entire `journalEntry.ledgerEntries.account` graph for all cash entries; only the first contra-account type is used |
| `src/Models/JournalEntry.php` | 118 | Medium | Post-loop account recalculation issues `Account::find()` per account ID; should batch with `whereIn` |
| `src/Models/Account.php` | 213 | Medium | `debit()`/`credit()` call `$this->refresh()` after every write; `cached_balance` is already updated by the `created` hook — redundant round-trip |
| `src/Services/Reports/CashFlowStatement.php` | 148 | Medium | `getCashBalance()` issues 2 separate SUM queries per call; called twice in `generate()` = 4 queries for 2 dates |
| `src/Models/Account.php` | 352 | Medium | `recalculateBalance()` triggers 2 aggregate queries + 1 UPDATE per call; called after every single entry creation with no deduplication guard |
| `src/Models/JournalEntry.php` | 146 | Medium | `pluck('account_id')` called after already iterating `ledgerEntries()->get()` — redundant query |
| `src/migrations/...ledger_entries_table.php` | 37 | Medium | Standalone `account_id` index is made redundant by later composite index `(account_id, is_posted, post_date)` — wastes write overhead |
| `src/migrations/...accounts_table.php` | 34 | Low | No composite index on `(type, is_active)` despite every report filtering on both columns together |

### Architecture / Design

| File | Line | Severity | Description |
|------|------|----------|-------------|
| `accounting-master/` | 0 | Critical | Entire parallel legacy codebase (namespace `Scottlaurent\Accounting`) coexists with the active `src/` codebase — dead code, confusion about canonical implementation |
| `src/Models/Account.php` | 1 | High | God class: Eloquent model, balance calculation, direct posting, increase/decrease wrappers, and daily activity reporting all in one 360-line class (SRP violation) |
| `src/Models/Account.php` | 208 | High | `Account::debit()` and `Account::credit()` bypass double-entry invariant — standalone entries have no balancing counterpart and cannot be reversed via standard workflow |
| `src/Services/Reports/BalanceSheet.php` | 30 | Medium | `BalanceSheet::generate()` directly calls `IncomeStatement::generate()` — tight coupling between report classes |
| `src/Services/Reports/BalanceSheet.php` | 21 | Medium | All five report classes use exclusively static methods — untestable in isolation, no dependency injection possible |
| `src/Services/Reports/IncomeStatement.php` | 39 | Medium | Debit/credit aggregation + normal-balance net computation logic duplicated verbatim across at least 8 locations |
| `src/Models/JournalEntry.php` | 87 | Medium | Running balance computation duplicated between `LedgerEntry::boot()` creating hook and `JournalEntry::post()` method |
| `src/Models/Account.php` | 88 | Medium | `balance` virtual attribute returns `cached_balance`; `getBalance()` computes from ledger entries — two divergent balance values can exist silently with no warning to callers |
| `config/accounting.php` | 1 | Low | `base_currency` config value unused; codebase hardcodes `'USD'` string literals throughout instead of reading from config |
| `src/Services/Reports/AgingReport.php` | 74 | Low | Aging report treats individual ledger entries as independent items rather than grouping by invoice/customer reference for net outstanding amount |

### Logic Errors

| File | Line | Severity | Description |
|------|------|----------|-------------|
| `src/Services/Reports/BalanceSheet.php` | 31 | High | Net income period start hardcoded to `Carbon::create($asOf->year, 1, 1)` — incorrect for non-calendar fiscal years |
| `src/Services/Reports/CashFlowStatement.php` | 64 | High | Outer `match` on `$contraType` has no `default` arm — any future fourth category string from `determineContraType()` throws an unhandled `UnhandledMatchError` at runtime |
| `src/Models/LedgerEntry.php` | 82 | High | `updating` hook allows direct mutation of `is_posted` and `running_balance` fields, bypassing the journal-level post/unpost workflow and leaving balances stale |
| `src/Models/Account.php` | 114 | Medium | `isDebitNormal()` silently returns `true` when `$this->type` is null — malformed accounts default to debit-normal behavior, corrupting balance sheet and trial balance for liability/equity/income accounts with missing type |
| `src/Models/LedgerEntry.php` | 63 | Medium | When `Account::find($entry->account_id)` returns null, the else-branch silently treats the missing account as credit-normal rather than throwing an exception |
| `src/Services/Reports/AgingReport.php` | 85 | Medium | `diffInDays()` returns absolute value — future-dated entries are indistinguishable from past entries and are incorrectly bucketed as aged |
| `src/Services/Reports/IncomeStatement.php` | 65 | Low | Income accounts with `sub_type === null` are silently promoted to revenue; null-sub_type expense accounts default to operating — both are data quality issues that should surface as warnings |
| `src/Models/JournalEntry.php` | 40 | Low | `empty($entry->id)` is fragile for UUID detection; `null === $entry->id` is more explicit and strict-types correct |
| `src/Models/JournalEntry.php` | 130 | Medium | `unpost()` resets `running_balance` to 0 on all entries but does not re-sequence running balances on any subsequently-posted entries that chained off the unposted ones |

### Dead Code

| File | Line | Severity | Description |
|------|------|----------|-------------|
| `src/Models/LedgerEntry.php` | 129 | Medium | `referencesModel()` is `@deprecated`, always throws `ImmutableEntryException`, and is never called — pure dead code |
| `config/accounting.php` | 6 | Medium | `audit` configuration section defined but the audit feature is not implemented; config key is never referenced |
| `src/Models/Account.php` | 149 | Low | `getCurrentBalance()` is a trivial alias for `getBalance()` with no added behavior; duplicated by `getCurrentBalanceInDollars()` aliasing `getBalanceInDollars()` |
| `src/Models/Account.php` | 181 | Low | `getDebitBalanceOn()` is defined but never called outside the class |
| `src/Models/Account.php` | 191 | Low | `getCreditBalanceOn()` is defined but never called outside the class |
| `src/Models/Account.php` | 311 | Low | `getDollarsDebitedOn()` is only called by `getDollarsDebitedToday()` — consider making it private |
| `src/Models/Account.php` | 321 | Low | `getDollarsCreditedOn()` is only called by `getDollarsCreditedToday()` — consider making it private |
| `composer.json` | 24 | Low | `fakerphp/faker` dev dependency is never used in any test file |
| `composer.json` | 24 | Low | `mockery/mockery` dev dependency is never used in any test file |

### Dependencies

| File | Line | Severity | Description |
|------|------|----------|-------------|
| `package-lock.json` | 189 | Medium | `js-yaml` v3.14.2 (bundled under `gray-matter`) has a known ReDoS CVE (GHSA-p9pc-299p-vxgp / CVE-2023-2251) |
| `composer.json` | 19 | Medium | `moneyphp/money` pinned to `^3.3.3` (last release 2022, unmaintained); v4.6.x is the current stable |
| `composer.json` | 53 | Medium | `minimum-stability: dev` allows resolution of dev-channel releases across the entire dependency tree |
| `.github/workflows/claude-code-review.yml` | 1 | Medium | No `composer audit` or `npm audit` step in any CI workflow |
| `composer.json` | 28 | Low | `allow-plugins` incorrectly lists `phpunit/phpunit` and `orchestra/testbench` which are not Composer plugins |
| `composer.lock` | 2251 | Low | `nette/schema` and `nette/utils` carry GPL-2.0 / GPL-3.0 license options — legal review required for distribution |
| `package.json` | 3 | Low | `@willbots/orchestrator` declared as `UNLICENSED` — compliance ambiguity |
| `package-lock.json` | 165 | Low | `js-yaml` appears in two versions (v3 under `gray-matter`, v4 top-level) — dual parser with different security profiles |
| `composer.lock` | 2983 | Low | `ralouphie/getallheaders` v3.0.3 last released 2019, unmaintained |

### Test Coverage Gaps

| File | Line | Severity | Description |
|------|------|----------|-------------|
| `src/Services/TransactionBuilder.php` | 153 | High | No test verifies that a mid-commit failure rolls back all state; no partial persistence coverage |
| `src/Models/JournalEntry.php` | 163 | High | `reverse()` called on an unposted entry (LogicException path) is untested |
| `src/Models/JournalEntry.php` | 200 | High | `void()` called on an unposted entry (LogicException path) is untested |
| `src/Services/Reports/BalanceSheet.php` | 21 | High | `generate()` with historical or non-calendar-year dates untested; hardcoded Jan 1 boundary has no regression |
| `src/Services/Reports/AgingReport.php` | 32 | High | `generate()` with `AccountType::EQUITY`, `INCOME`, or `EXPENSE` is completely untested |
| `src/Services/Reports/CashFlowStatement.php` | 26 | High | `generate()` with a specific `$cashAccount` parameter is untested |
| `src/Models/Account.php` | 96 | High | `setBalanceAttribute` accepts `true`, `false`, `null`, and non-numeric strings silently — none of these edge cases are tested |
| `src/Models/Account.php` | 114 | Medium | `isDebitNormal()` fallback when `$this->type` is null is untested |
| `src/Models/Account.php` | 208 | Medium | `debit()`/`credit()` with mismatched currency Money object untested |
| `src/Models/JournalEntry.php` | 87 | Medium | Running balance chaining across sequential posts to the same account untested in isolation |
| `src/Models/JournalEntry.php` | 130 | Medium | Unposting first of two sequential transactions; effect on the second transaction's running balance untested |
| `src/Models/JournalEntry.php` | 163 | Medium | Reversal entry running balances not verified by existing tests |
| `src/Models/LedgerEntry.php` | 82 | Medium | Direct `running_balance` update on an existing entry (the allowed update path) untested; mixed-field update blocking untested |
| `src/Services/TransactionBuilder.php` | 153 | Medium | `commit()` with zero entries (empty journal) creates an empty JournalEntry — untested and possibly unintended |
| `src/Services/Reports/BalanceSheet.php` | 101 | Medium | Zero-balance accounts should be excluded from output — filtering behavior untested |
| `src/Services/Reports/IncomeStatement.php` | 19 | Medium | `generate()` with `from > to` date range produces undefined/undocumented behavior |
| `src/Services/Reports/TrialBalance.php` | 17 | Medium | Abnormal balance scenario (debit-normal account with net credit) untested |
| `src/Services/Reports/CashFlowStatement.php` | 115 | Medium | `determineContraType()` fallback paths (no journal entry, single-line journal, soft-deleted contra-account) untested |
| `tests/Unit/AccountModelTest.php` | 194 | Medium | `Carbon::setTestNow()` not reset in a `finally` block — time mock leaks into subsequent tests on assertion failure |
| `tests/Functional/ReportsTest.php` | 286 | Medium | `Carbon::setTestNow()` not reset in `finally` in aging report test — leaks on failure |
| `tests/Functional/ReportsTest.php` | 307 | Medium | Same `Carbon::setTestNow()` leak in `aging_report_with_custom_buckets` |
| `src/Models/Account.php` | 251 | Low | `debitDollars()`/`creditDollars()` with fractional cents (`0.001`) and negative values untested |

### Documentation

| File | Line | Severity | Description |
|------|------|----------|-------------|
| `.env.example` | 1 | High | No `.env.example` file exists to document required environment variables |
| `CHANGELOG.md` | 1 | High | No changelog file exists |
| `docs/SETUP.md` | 1 | High | No step-by-step onboarding guide for new developers |
| `docs/ARCHITECTURE.md` | 1 | Medium | No architecture decision records explaining running_balance storage, immutability design, soft-delete rationale |
| `src/Models/Account.php` | 49 | Medium | Account model class lacks PHPDoc |
| `src/Models/LedgerEntry.php` | 12 | Medium | LedgerEntry lacks docblock explaining immutability constraint |
| `src/Models/JournalEntry.php` | 11 | Medium | JournalEntry lacks docblock explaining UUID PK and posting lifecycle |
| `src/Services/TransactionBuilder.php` | 18 | Medium | TransactionBuilder lacks docblock explaining builder pattern and double-entry validation guarantees |
| `config/accounting.php` | 1 | Medium | Configuration options lack inline comments explaining each setting |
| `README.md` | 849 | Medium | Testing section does not document how to write new tests or test structure |
| `README.md` | 1 | Medium | Public API incomplete: `getDebitBalanceOn()`, `getCreditBalanceOn()`, `entriesReferencingModel()`, and report helper methods not documented |
| `docker-compose.yml` | 1 | Medium | No documentation of environment variable customization or security implications |
| `src/Traits/HasAccounting.php` | 13 | Low | Trait lacks docblock explaining how to attach accounting to a model |
| `src/Providers/AccountingServiceProvider.php` | 9 | Low | Service provider lacks docblock |

---

## Detailed Findings

### CRITICAL — Data Integrity: Race condition in LedgerEntry creating hook corrupts running balances
- **File:** `src/Models/LedgerEntry.php` (line 56)
- **Agents:** code-quality, performance
- **Description:** The `creating` boot hook computes `running_balance` by querying the most recent posted entry for the account using `latest('id')`. Under concurrent inserts for the same account this is a classic TOCTOU race: two entries can be created simultaneously, both read the same `$lastBalance`, and both write the same `running_balance`. The problem is amplified by `TransactionBuilder::commit()` which creates ledger entries in a loop with no account-level locking. Two extra queries per entry (last-balance SELECT + Account::find) are also fired inside the hook, producing 2N queries for N entries in a batch.
- **Suggested Fix:** Eliminate the per-creation running balance computation entirely. Accept `running_balance = 0` on creation and compute the full running balance sequence in a single sequential pass, protected by a `DB::transaction()` with `SELECT ... FOR UPDATE` on the affected account rows.

### CRITICAL — Data Integrity: JournalEntry::post() running balance is stale for multi-line same-account entries
- **File:** `src/Models/JournalEntry.php` (line 100)
- **Agents:** code-quality, performance
- **Description:** Inside `post()`, the code queries `LedgerEntry::where('account_id', ...)->where('is_posted', true)->latest('id')->first()` for each entry in the loop. However, the entries being posted are not yet marked `is_posted = true` in the database at query time. If two lines in the same journal entry reference the same account, both will read the same `$lastBalance` and produce the same `running_balance`. Additionally, sorting by `latest('id')` is not equivalent to ordering by `post_date` — out-of-order chronological posting will produce incorrect sequences.
- **Suggested Fix:** Before the loop, bulk-set `is_posted = true` on all entries in a single UPDATE, then recompute running balances in a sequential in-memory pass over entries sorted by `(post_date, id)`, starting from the last known balance queried once per unique account.

### CRITICAL — Architecture: Entire parallel legacy codebase in accounting-master/
- **File:** `accounting-master/` (line 0)
- **Agents:** architecture
- **Description:** The `accounting-master/` directory contains a complete, separate implementation of the accounting domain under the namespace `Scottlaurent\Accounting`. It has its own `composer.json`, models, migrations, service provider, and tests. Neither autoload configuration references the other. The old code uses different architectural patterns (MorphOne journals, string-based transaction groups, `$guarded` instead of `$fillable`, mutable transactions) that directly contradict the design decisions in the active `src/` codebase.
- **Suggested Fix:** Tag the current state as a reference point (`git tag v0-scottlaurent-fork`) and delete the `accounting-master/` directory from the working tree entirely.

### CRITICAL — Performance: N+1 queries in BalanceSheet report (120+ queries per render)
- **File:** `src/Services/Reports/BalanceSheet.php` (line 86)
- **Agents:** performance, code-quality, architecture
- **Description:** `getAccountBalances()` loads accounts of a given type and issues two SUM queries per account (debit and credit) inside a foreach loop. `BalanceSheet::generate()` calls this three times (assets, liabilities, equity) and also delegates to `IncomeStatement::generate()` which repeats the same pattern. For a 40-account chart, a single balance sheet render produces over 120 individual aggregate queries against `accounting_ledger_entries`.
- **Suggested Fix:** Replace all per-account loops with a single `GROUP BY account_id` query using `selectRaw('account_id, SUM(debit) as total_debit, SUM(credit) as total_credit')`, keyed by account_id for O(1) lookup per account.

### CRITICAL — Performance: N+1 queries in IncomeStatement (60+ queries)
- **File:** `src/Services/Reports/IncomeStatement.php` (line 40)
- **Agents:** performance, code-quality
- **Description:** Two separate SUM queries are issued per income account and per expense account inside foreach loops. For 10 income + 20 expense accounts this is 60 queries. Because `BalanceSheet::generate()` calls `IncomeStatement::generate()` internally, every balance sheet request runs all 60 queries a second time.
- **Suggested Fix:** Collect all account IDs, run a single GROUP BY aggregate query, and build result maps. Reduce per-category query count from O(N) to O(1).

### CRITICAL — Performance: N+1 queries in TrialBalance (100+ queries for 50 accounts)
- **File:** `src/Services/Reports/TrialBalance.php` (line 32)
- **Agents:** performance, code-quality
- **Description:** `generate()` loads all active accounts then issues two SUM queries per account inside a foreach — one for debits, one for credits. For 50 accounts this is 100 individual aggregate queries with no pagination.
- **Suggested Fix:** Replace the foreach with a single GROUP BY query against `accounting_ledger_entries` to compute all debit and credit sums in one round-trip.

### HIGH — Security: Dynamic class instantiation from mass-assignable database column
- **File:** `accounting-master/src/Models/JournalTransaction.php` (line 74)
- **Agents:** security
- **Description:** `getReferencedObject()` instantiates `new $this->ref_class` where `ref_class` is a fully-qualified class name stored in the database column. Because `ref_class` is in `$fillable` (line 27), it can be set via mass assignment. An attacker who can write to this column can instantiate any autoloaded class, triggering constructor side effects or exploiting available gadget chains. This is CWE-470 / CWE-502.
- **Suggested Fix:** Validate `ref_class` against a whitelist before instantiation using `Relation::morphMap()` or an explicit `is_a()` check against `Model::class`. Remove `ref_class` and `ref_class_id` from `$fillable`.

### HIGH — Data Integrity: Account::debit()/credit() bypass double-entry accounting
- **File:** `src/Models/Account.php` (line 208)
- **Agents:** architecture, code-quality, security
- **Description:** These methods create standalone `LedgerEntry` records with no associated `JournalEntry` (journal_entry_id will be null). This breaks the double-entry invariant: a debit with no corresponding credit corrupts the accounting equation. Standalone entries cannot be reversed via the standard `JournalEntry::reverse()` workflow. The create + refresh pair is also not wrapped in a transaction.
- **Suggested Fix:** Deprecate `debit()`, `credit()`, `increase()`, and `decrease()` on `Account`. Require all entries to be created through `TransactionBuilder` which enforces balanced double-entry. At minimum wrap the create + refresh in `DB::transaction()` and document the bypassed invariant.

### HIGH — Data Integrity: Missing DB transaction in JournalEntry::post()
- **File:** `src/Models/JournalEntry.php` (line 97)
- **Agents:** code-quality, architecture
- **Description:** `post()` updates `is_posted` on the journal entry, then loops through ledger entries updating each one individually without any transaction wrapper. A database failure mid-loop leaves some entries posted and others not, and the account balance recalculation at the end may be skipped entirely, resulting in stale `cached_balance` values.
- **Suggested Fix:** Wrap the entire `post()` body in `DB::transaction(function() { ... })`.

### HIGH — Data Integrity: Missing DB transaction in JournalEntry::unpost()
- **File:** `src/Models/JournalEntry.php` (line 139)
- **Agents:** code-quality, architecture
- **Description:** `unpost()` operates across multiple database rows without a transaction. A failure leaves a mixed state where some entries are `is_posted = false` and others remain posted, producing incorrect account balances.
- **Suggested Fix:** Wrap the entire `unpost()` body in `DB::transaction(function() { ... })`.

### HIGH — Data Integrity: Missing DB transaction in JournalEntry::reverse()
- **File:** `src/Models/JournalEntry.php` (line 178)
- **Agents:** code-quality, architecture
- **Description:** `reverse()` creates a new `JournalEntry` then iterates to create reversal `LedgerEntry` records without a transaction. If a ledger entry creation fails mid-loop, the reversal journal entry and some of its entries are partially committed, leaving the books inconsistent. The `void()` method has the identical problem.
- **Suggested Fix:** Wrap `reverse()` and `void()` bodies in `DB::transaction(function() { ... })`.

### HIGH — Data Integrity: reverse() does not recalculate balances for reversal-only accounts
- **File:** `src/Models/JournalEntry.php` (line 169)
- **Agents:** code-quality
- **Description:** The `recalculateBalance()` loop at line 188 iterates `$this->ledgerEntries` (the original journal entry's entries). If the reversal introduces accounts not present in the original (which should not normally happen but could in edge cases), those accounts' balances will not be updated. Additionally, when entries are created via the reversal loop they go through the `creating` boot hook which has the TOCTOU race described in finding #1, so reversal running balances are also potentially incorrect.
- **Suggested Fix:** After creating all reversal entries, collect the full set of affected account IDs from the reversal journal entry and call `recalculateBalance()` on each. Wrap in a transaction.

### HIGH — Logic Error: BalanceSheet hardcodes January 1 as fiscal year start
- **File:** `src/Services/Reports/BalanceSheet.php` (line 31)
- **Agents:** code-quality, test-coverage
- **Description:** Net income is computed using `Carbon::create($asOf->year, 1, 1)` as the period start. Any organization with a non-calendar fiscal year (e.g., fiscal year starting July 1) will get an incorrect net income figure on the balance sheet equity section.
- **Suggested Fix:** Add a `$periodStart` parameter to `BalanceSheet::generate()` so callers can specify the fiscal year start date. Default to January 1 of `$asOf->year` with a clear docblock warning.

### HIGH — Logic Error: CashFlowStatement match expression has no default arm
- **File:** `src/Services/Reports/CashFlowStatement.php` (line 64)
- **Agents:** code-quality
- **Description:** The outer `match ($contraType)` on line 64 handles only `'operating'`, `'investing'`, and `'financing'`. There is no `default` arm. Any future addition of a fourth category string from `determineContraType()` will throw an unhandled `UnhandledMatchError` at runtime with no catch handler.
- **Suggested Fix:** Add `default => $operating[] = $detail` as a safe fallback arm.

### HIGH — Logic Error: LedgerEntry updating hook allows direct bypass of posting workflow
- **File:** `src/Models/LedgerEntry.php` (line 82)
- **Agents:** code-quality
- **Description:** The `updating` hook allows changes to `is_posted` and `running_balance` as exceptions to the immutability rule. This means any code can call `$entry->is_posted = false; $entry->save()` directly, bypassing the journal-level `unpost()` workflow. When this happens, `running_balance` remains stale and `cached_balance` on the account is not recomputed.
- **Suggested Fix:** Remove `is_posted` and `running_balance` from `$allowedFields` in the updating hook. Only allow these fields to be changed via `JournalEntry::post()`/`unpost()` which perform full recalculation. Use `saveQuietly()` with guarded internal methods for necessary internal mutations.

### HIGH — Architecture: Account is a God Class violating SRP
- **File:** `src/Models/Account.php` (line 1)
- **Agents:** architecture
- **Description:** At 360 lines, the Account model carries five distinct responsibilities: Eloquent model/relationships, balance calculation from ledger entries, direct debit/credit posting, increase/decrease convenience wrappers, and daily activity reporting. As the package grows this class will become unmanageable.
- **Suggested Fix:** Extract posting logic into an `AccountPostingService`. Extract balance calculation into a `BalanceCalculator` service. Extract daily activity helpers into a reporting concern. The model should contain only relationships, accessors, and domain identity methods.

### HIGH — Test Coverage: No rollback test for TransactionBuilder::commit()
- **File:** `src/Services/TransactionBuilder.php` (line 153)
- **Agents:** test-coverage
- **Description:** No test verifies that if a failure occurs mid-commit, the entire transaction is rolled back and no partial `JournalEntry` or `LedgerEntry` records persist.
- **Suggested Fix:** Add a test that forces a failure during the ledger entry creation loop (e.g., via a hook that throws after the first entry) and asserts no records exist.

### HIGH — Test Coverage: reverse() and void() exception paths untested
- **File:** `src/Models/JournalEntry.php` (lines 163, 200)
- **Agents:** test-coverage
- **Description:** Both `reverse()` and `void()` throw a `LogicException` when called on an unposted journal entry. Neither exception path has test coverage.
- **Suggested Fix:** Add tests that call `reverse()` and `void()` on unposted `JournalEntry` instances and assert `LogicException` is thrown.

### HIGH — Test Coverage: BalanceSheet January 1 boundary untested
- **File:** `src/Services/Reports/BalanceSheet.php` (line 21)
- **Agents:** test-coverage
- **Description:** The hardcoded January 1 period start has no regression test. Generating a historical balance sheet for a prior year and asserting correct net income is not tested.
- **Suggested Fix:** Add tests for `BalanceSheet::generate()` with `$asOf` dates in prior years and assert the correct income period is used.

### HIGH — Test Coverage: AgingReport with non-AR/AP account types untested
- **File:** `src/Services/Reports/AgingReport.php` (line 32)
- **Agents:** test-coverage
- **Description:** `AgingReport::generate()` is only tested with ASSET and LIABILITY account types. Calling it with EQUITY, INCOME, or EXPENSE is completely untested.
- **Suggested Fix:** Add tests for each remaining AccountType and assert the expected bucketing behavior.

### HIGH — Test Coverage: CashFlowStatement with explicit $cashAccount untested
- **File:** `src/Services/Reports/CashFlowStatement.php` (line 26)
- **Agents:** test-coverage
- **Description:** The `$cashAccount` parameter path is untested; only the null (discover-all-banks) path has test coverage.
- **Suggested Fix:** Add a test passing a specific Account object and asserting only movements for that account are included.

### HIGH — Test Coverage: setBalanceAttribute edge cases untested
- **File:** `src/Models/Account.php` (line 96)
- **Agents:** test-coverage
- **Description:** `setBalanceAttribute` silently accepts and coerces `true`, `false`, `null`, and non-numeric strings to 0. None of these invalid inputs are covered by tests.
- **Suggested Fix:** Add unit tests for each of these invalid input types and assert the expected (or corrected, failing) behavior.

### HIGH — Performance: N+1 inside LedgerEntry creating boot hook
- **File:** `src/Models/LedgerEntry.php` (line 56)
- **Agents:** performance, code-quality
- **Description:** Two extra queries per entry creation (running balance SELECT + `Account::find()`). For a typical 2-line balanced journal entry commit, this is 4 extra queries on top of the 2 INSERTs.
- **Suggested Fix:** Pass an already-loaded Account reference into the creation context or cache accounts in a static map within the transaction scope to eliminate repeated finds.

### HIGH — Performance: N+1 query in JournalEntry::post() loop
- **File:** `src/Models/JournalEntry.php` (line 97)
- **Agents:** performance, code-quality
- **Description:** For each entry in the post loop: one lazy-load of `$entry->account` + one SELECT for the last running balance = 2N+1 queries. A separate `pluck('account_id')` is issued after having already iterated the full collection.
- **Suggested Fix:** Eager-load accounts: `$entries = $this->ledgerEntries()->with('account')->get()`. Pre-fetch last running balance per account in a single IN query. Collect account IDs from the in-memory collection.

### HIGH — Performance: Double recalculation per account per commit
- **File:** `src/Models/LedgerEntry.php` (line 75)
- **Agents:** performance, architecture
- **Description:** The `created` event hook calls `recalculateBalance()` per entry, and `TransactionBuilder::commit()` also calls it explicitly after the loop — two full SUM aggregate + UPDATE cycles per account per commit.
- **Suggested Fix:** Suppress the `created` event recalculation during batch creation (use `createQuietly()` or a static flag), and rely solely on the explicit post-loop recalculation.

### HIGH — Performance: AgingReport loads unbounded ledger entries into memory
- **File:** `src/Services/Reports/AgingReport.php` (line 68)
- **Agents:** performance
- **Description:** All posted ledger entries up to `$asOf` are fetched with `->get()` for each account, with no limit or pagination. On long-lived AR/AP accounts with thousands of entries, this loads the entire history into PHP memory.
- **Suggested Fix:** Push the aging bucket logic to the database using conditional `CASE` aggregation to return only bucket totals.

### HIGH — Performance: getBalance() issues two separate SUM queries
- **File:** `src/Models/Account.php` (line 134)
- **Agents:** performance
- **Description:** `getBalance()` calls `.sum('debit')` and `.sum('credit')` as two separate queries with identical WHERE clauses. `getBalanceOn()` does the same with an extra redundant `endOfDay()` computation.
- **Suggested Fix:** Use a single `selectRaw('SUM(debit) as d, SUM(credit) as c')` query. Apply same pattern to all balance-on variants.

### HIGH — Performance: CashFlowStatement eager-loads entire nested graph
- **File:** `src/Services/Reports/CashFlowStatement.php` (line 36)
- **Agents:** performance
- **Description:** `->with('journalEntry.ledgerEntries.account')` loads the full nested graph into PHP memory; only the first contra-account's type field is actually used.
- **Suggested Fix:** Use a selective JOIN query that returns only the contra-account's type for categorization, avoiding loading full model graphs.

### MEDIUM — Security: Mass-assignable polymorphic type columns (Account)
- **File:** `src/Models/Account.php` (line 34)
- **Agents:** security, architecture
- **Description:** `accountable_type` and `accountable_id` are in `$fillable`. Without `Relation::enforceMorphMap()`, any class name can be stored and resolved. `cached_balance` is also in `$fillable`, allowing external manipulation of the reported account balance.
- **Suggested Fix:** Remove `accountable_type`, `accountable_id`, and `cached_balance` from `$fillable`. Configure `Relation::enforceMorphMap()` in `AccountingServiceProvider`.

### MEDIUM — Security: Mass-assignable polymorphic type columns (LedgerEntry)
- **File:** `src/Models/LedgerEntry.php` (line 27)
- **Agents:** security
- **Description:** `ledgerable_type` and `ledgerable_id` are in `$fillable` with no morph map enforcement.
- **Suggested Fix:** Remove from `$fillable` or restrict via `Relation::enforceMorphMap()`.

### MEDIUM — Security: Hardcoded credentials in docker-compose files
- **File:** `docker-compose.yml` (line 19), `accounting-master/docker-compose.yml` (line 27)
- **Agents:** security
- **Description:** Database passwords (`DB_PASSWORD=password`, `MYSQL_ROOT_PASSWORD=secret`) are hardcoded in committed compose files. CWE-798.
- **Suggested Fix:** Use environment variable interpolation (`${DB_PASSWORD:-password}`) and supply values via a `.env` file (excluded from git).

### MEDIUM — Security: No morphMap/enforceMorphMap configured
- **File:** `src/Providers/AccountingServiceProvider.php` (line 1)
- **Agents:** security
- **Description:** The service provider does not call `Relation::morphMap()` or `Relation::enforceMorphMap()`. Full class names are stored in database morph columns, coupling the database schema to namespace structure and exposing internal class names.
- **Suggested Fix:** Add `Relation::enforceMorphMap([...])` in `AccountingServiceProvider::boot()` mapping short aliases to allowed model classes.

### MEDIUM — Logic Error: isDebitNormal() silently defaults to true for null type
- **File:** `src/Models/Account.php` (line 114)
- **Agents:** code-quality, test-coverage
- **Description:** When `$this->type` is null, `isDebitNormal()` returns `true` (debit-normal). A malformed account with no type is silently treated as an asset, corrupting balance calculations for any liability, equity, or income account that has a missing type due to a bug or data migration issue.
- **Suggested Fix:** Throw an `InvalidArgumentException` or `LogicException` when `type` is null, or enforce a non-nullable database column.

### MEDIUM — Logic Error: Null account silently treated as credit-normal in LedgerEntry
- **File:** `src/Models/LedgerEntry.php` (line 63)
- **Agents:** code-quality
- **Description:** When `Account::find($entry->account_id)` returns null, the else-branch applies `running_balance = lastBalance + credit - debit`, silently treating the missing account as credit-normal.
- **Suggested Fix:** Throw an exception or log a critical error when the account cannot be resolved rather than applying an arbitrary default.

### MEDIUM — Logic Error: AgingReport uses absolute date difference; future-dated entries misbucket
- **File:** `src/Services/Reports/AgingReport.php` (line 85)
- **Agents:** code-quality
- **Description:** `diffInDays()` returns an absolute value, so a future-dated entry is indistinguishable from a past entry aged by the same number of days and is incorrectly bucketed.
- **Suggested Fix:** Use `$asOf->diffInDays($entry->post_date, false)` to get a signed difference and skip or separately report entries where the result is negative.

### MEDIUM — Logic Error: unpost() resets running_balance but does not re-sequence subsequent entries
- **File:** `src/Models/JournalEntry.php` (line 130)
- **Agents:** test-coverage, code-quality
- **Description:** When a journal entry is unposted, its ledger entries have `running_balance` reset to 0. Any subsequently-posted entries that computed their running balance starting from the now-unposted entries' balance will have stale (incorrect) running balances.
- **Suggested Fix:** After unposting, trigger a full running balance re-sequencing for each affected account from the earliest affected entry forward.

### MEDIUM — Architecture: BalanceSheet tightly coupled to IncomeStatement
- **File:** `src/Services/Reports/BalanceSheet.php` (line 30)
- **Agents:** architecture
- **Description:** `BalanceSheet::generate()` directly instantiates and calls `IncomeStatement::generate()` for net income, creating an untestable hard dependency.
- **Suggested Fix:** Extract net income calculation into a shared `NetIncomeCalculator` service that both reports delegate to.

### MEDIUM — Architecture: Report classes use exclusively static methods (no DI)
- **File:** `src/Services/Reports/BalanceSheet.php` (line 21)
- **Agents:** architecture
- **Description:** All five report classes use only static methods, making them impossible to mock in tests and preventing dependency injection. Violates DIP.
- **Suggested Fix:** Convert report classes to instantiable services with constructor-injected dependencies.

### MEDIUM — Architecture: Balance/credit/debit query logic duplicated in 8+ locations
- **File:** `src/Services/Reports/IncomeStatement.php` (line 39)
- **Agents:** architecture
- **Description:** The pattern of querying ledger entry sums + normal-balance-aware net + zero-balance filtering is copy-pasted across BalanceSheet, IncomeStatement, TrialBalance, AgingReport, and Account.
- **Suggested Fix:** Create a shared `AccountBalanceQuery` service that all reports and the Account model delegate to.

### MEDIUM — Architecture: balance attribute vs getBalance() can diverge silently
- **File:** `src/Models/Account.php` (line 88)
- **Agents:** architecture
- **Description:** The `balance` virtual attribute returns `cached_balance` (potentially stale), while `getBalance()` computes from ledger entries (live). Callers may not know which one to use, and silent divergence creates confusion.
- **Suggested Fix:** Rename the accessor to `getCachedBalanceAttribute()` to make explicit it is a cached value, not a live calculation.

### MEDIUM — Dead Code: referencesModel() is deprecated dead code that always throws
- **File:** `src/Models/LedgerEntry.php` (line 129)
- **Agents:** dead-code
- **Description:** This method is `@deprecated`, always throws `ImmutableEntryException`, and is never called anywhere in the codebase.
- **Suggested Fix:** Remove the method entirely.

### MEDIUM — Dead Code: audit configuration section unimplemented
- **File:** `config/accounting.php` (line 6)
- **Agents:** dead-code, architecture
- **Description:** The `audit` configuration block is defined but no audit feature is implemented. The key is never read by any code.
- **Suggested Fix:** Remove the audit configuration block, or implement the feature with corresponding tests.

### MEDIUM — Dependencies: js-yaml CVE (ReDoS) in package-lock.json
- **File:** `package-lock.json` (line 189)
- **Agents:** dependencies
- **Description:** `js-yaml` v3.14.2 (bundled as a transitive dependency under `gray-matter`) has a known ReDoS vulnerability (CVE-2023-2251 / GHSA-p9pc-299p-vxgp).
- **Suggested Fix:** Update `gray-matter` to a version that depends on `js-yaml` v4.x. Run `npm audit` regularly as part of CI.

### MEDIUM — Dependencies: moneyphp/money pinned to unmaintained v3.x
- **File:** `composer.json` (line 19)
- **Agents:** dependencies
- **Description:** `moneyphp/money` is pinned to `^3.3.3`, which has had no releases since 2022. The current stable is v4.6.x.
- **Suggested Fix:** Upgrade the constraint to `^4.0` and run `composer update moneyphp/money`.

### MEDIUM — Dependencies: minimum-stability set to dev
- **File:** `composer.json` (line 53)
- **Agents:** dependencies
- **Description:** `minimum-stability: dev` allows Composer to resolve dev-channel releases of any package in the dependency tree if no stable version satisfies a constraint.
- **Suggested Fix:** Change to `stable`. Use per-package stability flags if specific packages require dev channel.

### MEDIUM — Dependencies: No security audit in CI
- **File:** `.github/workflows/claude-code-review.yml` (line 1)
- **Agents:** dependencies
- **Description:** No CI step runs `composer audit` or `npm audit`, so known CVEs can enter the codebase undetected.
- **Suggested Fix:** Add `composer audit --no-dev` and `npm audit --audit-level=high` to the CI workflow.

### MEDIUM — Test Coverage: Multiple flaky test time mocks
- **File:** `tests/Unit/AccountModelTest.php` (line 194), `tests/Functional/ReportsTest.php` (lines 286, 307)
- **Agents:** test-coverage
- **Description:** Three tests set `Carbon::setTestNow()` without resetting in a `finally` block. A failing assertion leaks the mocked time into all subsequent tests in the run.
- **Suggested Fix:** Move `Carbon::setTestNow(null)` into the test class's `tearDown()` method, or wrap each test body in `try/finally`.

### MEDIUM — Performance: Account::debit()/credit() call redundant $this->refresh()
- **File:** `src/Models/Account.php` (line 213)
- **Agents:** performance
- **Description:** After each `debit()` or `credit()` call, `$this->refresh()` issues a full `SELECT *` to reload the model. The `cached_balance` is already updated by the `created` hook's `recalculateBalance()` call, making the refresh redundant.
- **Suggested Fix:** Remove `$this->refresh()`. Manually assign the updated `cached_balance` after `recalculateBalance()` returns.

### MEDIUM — Performance: Redundant pluck query after already iterating entries
- **File:** `src/Models/JournalEntry.php` (line 146)
- **Agents:** performance
- **Description:** In both `post()` and `unpost()`, a separate `->pluck('account_id')->unique()` query is issued after the foreach has already iterated the full entry collection.
- **Suggested Fix:** Collect `$accountIds[] = $entry->account_id` inside the foreach loop and use `array_unique()` after.

### MEDIUM — Performance: recalculateBalance() fires per entry with no deduplication guard
- **File:** `src/Models/Account.php` (line 352)
- **Agents:** performance
- **Description:** `recalculateBalance()` is called after every single ledger entry creation, after every post/unpost, and after every reverse/void with no guard against multiple calls within a single unit of work.
- **Suggested Fix:** Introduce a deferred recalculation pattern: collect account IDs needing recalculation during a transaction, then recalculate once per account after the transaction commits using `DB::afterCommit`.

### MEDIUM — Performance: Redundant standalone account_id index
- **File:** `src/migrations/2025_01_01_000003_create_accounting_ledger_entries_table.php` (line 37)
- **Agents:** performance
- **Description:** The standalone `account_id` index is made redundant by the later composite index `(account_id, is_posted, post_date)` added in migration 000006. The duplicate index wastes write overhead on every INSERT.
- **Suggested Fix:** Remove the standalone `account_id` index from migration 000003 in a new migration.

### MEDIUM — Performance: getCashBalance() issues 4 queries for 2 dates
- **File:** `src/Services/Reports/CashFlowStatement.php` (line 148)
- **Agents:** performance
- **Description:** `getCashBalance()` calls two separate SUM queries per invocation. It is called twice in `generate()`, resulting in 4 queries to compute beginning and ending balances.
- **Suggested Fix:** Use a single `selectRaw('SUM(debit) as d, SUM(credit) as c')` query per call to halve the query count.

### MEDIUM — Performance: N+1 account recalculation after post (Account::find per account)
- **File:** `src/Models/JournalEntry.php` (line 118)
- **Agents:** performance
- **Description:** After posting, each unique account ID triggers a separate `Account::find()` query plus a `recalculateBalance()` aggregate + UPDATE. For N distinct accounts this is 2N queries.
- **Suggested Fix:** Batch with `Account::whereIn('id', $accountIds)->get()` then iterate to call `recalculateBalance()`.

### MEDIUM — Test Coverage: Various untested paths
- **File:** Multiple (see test coverage table above)
- **Agents:** test-coverage
- **Description:** Key untested paths include: `commit()` with zero entries, zero-balance account exclusion in BalanceSheet, `IncomeStatement::generate()` with `from > to`, abnormal balance in TrialBalance, and `determineContraType()` fallback paths.
- **Suggested Fix:** See per-file suggested fixes in the test coverage table above.

### LOW — Security: APP_DEBUG=true in committed compose files
- **File:** `docker-compose.yml` (line 12), `accounting-master/docker-compose.yml` (line 12)
- **Agents:** security
- **Description:** `APP_DEBUG=true` in committed compose files. If used outside development, Laravel exposes detailed stack traces in error responses (CWE-209).
- **Suggested Fix:** Use separate override files for production; add comment marking files as development-only.

### LOW — Security: APP_KEY empty string
- **File:** `docker-compose.yml` (line 13)
- **Agents:** security
- **Description:** Empty `APP_KEY` means Laravel encryption is disabled or will fail, affecting cookies, sessions, and other encrypted data (CWE-326).
- **Suggested Fix:** Generate with `php artisan key:generate` and supply via environment variable.

### LOW — Security: Root .env not in .gitignore
- **File:** `.gitignore` (line 1)
- **Agents:** security
- **Description:** The root `.env` file is not explicitly listed in `.gitignore`. The `composer.json` post-install script creates a `.env` from `.env.example`, which could be accidentally committed (CWE-538).
- **Suggested Fix:** Add `.env` and `.env.*` (except `.env.example`) to the root `.gitignore`.

### LOW — Security: Xdebug enabled with start_with_request=yes
- **File:** `docker/php/xdebug.ini` (line 3)
- **Agents:** security
- **Description:** Every request triggers Xdebug. If deployed to production this leaks stack traces and internal state (CWE-489).
- **Suggested Fix:** Use multi-stage Docker builds to exclude Xdebug from the production image.

### LOW — Dead Code: Redundant getCurrentBalance() / getCurrentBalanceInDollars()
- **File:** `src/Models/Account.php` (line 149)
- **Agents:** dead-code, architecture
- **Description:** Both methods are exact one-line wrappers around `getBalance()` and `getBalanceInDollars()` with no added behavior.
- **Suggested Fix:** Add `@deprecated` annotations and remove in the next major version.

### LOW — Dead Code: getDebitBalanceOn() / getCreditBalanceOn() never called externally
- **File:** `src/Models/Account.php` (lines 181, 191)
- **Agents:** dead-code
- **Description:** Neither method is called outside the class. If they are part of the intended public API, they need tests.
- **Suggested Fix:** Add test coverage if keeping; otherwise remove or make private.

### LOW — Dead Code: getDollarsDebitedOn() / getDollarsCreditedOn() should be private
- **File:** `src/Models/Account.php` (lines 311, 321)
- **Agents:** dead-code
- **Description:** Both methods are only called by their `*Today()` counterparts.
- **Suggested Fix:** Change visibility to `private` or document as intentional public API with tests.

### LOW — Dead Code: Unused dev dependencies
- **File:** `composer.json` (line 24)
- **Agents:** dead-code
- **Description:** `fakerphp/faker` and `mockery/mockery` are listed as dev dependencies but are never imported or used in any test file.
- **Suggested Fix:** Remove both from `require-dev`.

### LOW — Architecture: Config base_currency unused; hardcoded USD throughout
- **File:** `config/accounting.php` (line 1)
- **Agents:** architecture, dead-code
- **Description:** `base_currency` config key is never read; all code hardcodes `'USD'` string literals.
- **Suggested Fix:** Replace all `'USD'` literals with `config('accounting.base_currency', 'USD')`.

### LOW — Logic Error: Income/expense accounts with null sub_type silently miscategorized
- **File:** `src/Services/Reports/IncomeStatement.php` (line 65)
- **Agents:** code-quality
- **Description:** Income accounts with `sub_type === null` are silently promoted to revenue rows; expense accounts with `sub_type === null` go to operating expenses. Both are silent data quality misclassifications.
- **Suggested Fix:** Add an explicit null sub_type check and route these to an 'Uncategorised' bucket or log a warning.

### LOW — Logic Error: empty($entry->id) is fragile for UUID detection
- **File:** `src/Models/JournalEntry.php` (line 40)
- **Agents:** code-quality
- **Description:** `empty()` in PHP treats `'0'`, `''`, `null`, and `false` as empty. Using it on a typed string UUID field is fragile under `declare(strict_types=1)`.
- **Suggested Fix:** Replace with `!isset($entry->id) || $entry->id === ''` for strict correctness.

### LOW — Dependencies: allow-plugins misconfiguration
- **File:** `composer.json` (line 28)
- **Agents:** dependencies
- **Description:** `phpunit/phpunit` and `orchestra/testbench` are listed in `allow-plugins`. Neither is a Composer plugin — they are test frameworks.
- **Suggested Fix:** Remove both from the `allow-plugins` list.

### LOW — Dependencies: License compliance for nette/* packages
- **File:** `composer.lock` (line 2251)
- **Agents:** dependencies
- **Description:** `nette/schema` and `nette/utils` carry GPL-2.0-only and GPL-3.0-only as license options alongside BSD-3-Clause. GPL copyleft terms may impose obligations.
- **Suggested Fix:** Confirm with legal counsel that using these packages under the BSD-3-Clause option satisfies distribution requirements.

### LOW — Performance: Missing composite index on accounts (type, is_active)
- **File:** `src/migrations/2025_01_01_000001_create_accounting_accounts_table.php` (line 34)
- **Agents:** performance
- **Description:** Every report generator filters `accounting_accounts` by both `type` and `is_active`. No composite index covers both columns, requiring the database to apply one index and filter the other in memory.
- **Suggested Fix:** Add a composite index `['type', 'is_active']` in a new migration.

---

## Prioritized Remediation Plan

### Priority 1 — Immediate Action Required (Critical)

- [ ] `src/Models/LedgerEntry.php:56` — TOCTOU race in creating hook corrupts running balances — Effort: L
  Fix: Remove per-creation running balance from the creating hook; compute in a single sequential pass with account-level locking inside `DB::transaction()`.

- [ ] `src/Models/JournalEntry.php:100` — post() stale-read corrupts running balances for multi-line same-account entries — Effort: L
  Fix: Bulk mark entries posted first, then compute running balances in an in-memory sequential pass sorted by `(post_date, id)`.

- [ ] `accounting-master/` — Remove entire parallel legacy codebase — Effort: S
  Fix: `git tag v0-scottlaurent-fork && git rm -r accounting-master/`

- [ ] `src/Services/Reports/BalanceSheet.php:86` — N+1 queries: 120+ aggregate queries per balance sheet render — Effort: M
  Fix: Replace per-account foreach with a single `GROUP BY account_id` aggregate query keyed by account_id.

- [ ] `src/Services/Reports/IncomeStatement.php:40` — N+1 queries: 60+ queries per income statement, doubled by BalanceSheet delegation — Effort: M
  Fix: Single `GROUP BY` aggregate query per category; build result map before iteration.

- [ ] `src/Services/Reports/TrialBalance.php:32` — N+1 queries: 100+ queries for 50-account chart — Effort: M
  Fix: Single `GROUP BY` aggregate query replacing the foreach loop.

### Priority 2 — Address Within This Sprint (High)

- [ ] `src/Models/JournalEntry.php:97` — post() not wrapped in DB transaction — Effort: S
  Fix: Wrap entire `post()` body in `DB::transaction(function() { ... })`.

- [ ] `src/Models/JournalEntry.php:139` — unpost() not wrapped in DB transaction — Effort: S
  Fix: Wrap entire `unpost()` body in `DB::transaction(function() { ... })`.

- [ ] `src/Models/JournalEntry.php:178` — reverse() not wrapped in DB transaction — Effort: S
  Fix: Wrap `reverse()` and `void()` bodies in `DB::transaction(function() { ... })`.

- [ ] `src/Models/JournalEntry.php:200` — void() not wrapped in DB transaction — Effort: S
  Fix: Same as reverse() fix above.

- [ ] `accounting-master/src/Models/JournalTransaction.php:74` — Dynamic class instantiation from mass-assignable database column — Effort: M
  Fix: Whitelist allowed classes before `new $this->ref_class`; remove `ref_class` from `$fillable`.

- [ ] `src/Models/Account.php:208` — debit()/credit() bypass double-entry invariant — Effort: L
  Fix: Deprecate standalone posting methods; require all entries through `TransactionBuilder`.

- [ ] `src/Services/Reports/BalanceSheet.php:31` — Net income hardcoded to Jan 1 fiscal year start — Effort: M
  Fix: Add `$periodStart` parameter to `generate()`; document the limitation.

- [ ] `src/Services/Reports/CashFlowStatement.php:64` — match expression missing default arm — Effort: S
  Fix: Add `default => $operating[] = $detail` arm to the outer match expression.

- [ ] `src/Models/LedgerEntry.php:82` — updating hook allows direct bypass of posting workflow — Effort: M
  Fix: Remove `is_posted` and `running_balance` from `$allowedFields`; require changes only via journal-level methods.

- [ ] `src/Models/Account.php:1` — God class violating SRP — Effort: XL
  Fix: Extract posting and balance-calculation logic into dedicated service classes.

- [ ] `src/Models/LedgerEntry.php:56` — N+1 in creating boot hook (2 queries per entry) — Effort: M
  Fix: Cache loaded accounts within transaction scope; eliminate per-creation Account::find().

- [ ] `src/Models/LedgerEntry.php:75` — Double balance recalculation per commit — Effort: M
  Fix: Use `createQuietly()` during batch creation; rely solely on explicit post-loop recalculation.

- [ ] `src/Models/JournalEntry.php:97` — N+1 in post() loop (2N+1 queries) — Effort: M
  Fix: Eager-load accounts; pre-fetch last running balance per account in a single IN query.

- [ ] `src/Models/JournalEntry.php:178` — N+1 in reverse()/void() lazy-loading account per entry — Effort: S
  Fix: Call `$this->loadMissing('ledgerEntries.account')` before foreach.

- [ ] `src/Services/Reports/AgingReport.php:68` — Unbounded memory load of all ledger entries — Effort: L
  Fix: Replace PHP-side bucketing with conditional SQL `CASE` aggregation.

- [ ] `src/Models/Account.php:134` — getBalance() issues two separate SUM queries — Effort: S
  Fix: Collapse into single `selectRaw('SUM(debit) as d, SUM(credit) as c')` query.

- [ ] `src/Services/Reports/CashFlowStatement.php:36` — Unnecessary full nested graph eager-loading — Effort: M
  Fix: Use a selective JOIN query returning only contra-account type column.

- [ ] `src/Services/TransactionBuilder.php:153` — No rollback test for mid-commit failure — Effort: M
  Fix: Add test that forces failure after first entry creation and asserts no records persist.

- [ ] `src/Models/JournalEntry.php:163,200` — reverse()/void() exception paths untested — Effort: S
  Fix: Add tests asserting `LogicException` on unposted entry calls.

- [ ] `src/Models/Account.php:96` — setBalanceAttribute edge cases (null/bool/string) untested — Effort: S
  Fix: Add unit tests for each invalid input type.

- [ ] `src/Services/Reports/AgingReport.php:32` — AgingReport with non-AR/AP account types untested — Effort: S
  Fix: Add tests for EQUITY, INCOME, EXPENSE account types.

- [ ] `src/Services/Reports/CashFlowStatement.php:26` — CashFlowStatement with explicit $cashAccount untested — Effort: S
  Fix: Add test with specific Account parameter and assert scoped results.

- [ ] `src/Services/Reports/BalanceSheet.php:21` — BalanceSheet Jan 1 boundary untested — Effort: S
  Fix: Add tests for prior-year `$asOf` dates; assert correct income period.

- [ ] `src/Models/JournalEntry.php:169` — reverse() misses balance recalculation for reversal-only accounts — Effort: S
  Fix: Collect all account IDs from reversal entry and call `recalculateBalance()` on each.

- [ ] `src/Models/JournalEntry.php:130` — unpost() doesn't re-sequence subsequent running balances — Effort: L
  Fix: After unpost, trigger full running balance re-sequencing for each affected account from the earliest affected entry.

### Priority 3 — Schedule for Next Sprint (Medium)

- [ ] `src/Models/Account.php:34` — accountable_type/accountable_id/cached_balance mass-assignable — Effort: S
  Fix: Remove from `$fillable`.

- [ ] `src/Models/LedgerEntry.php:27` — ledgerable_type/ledgerable_id mass-assignable — Effort: S
  Fix: Remove from `$fillable`.

- [ ] `src/Providers/AccountingServiceProvider.php:1` — No morphMap enforcement — Effort: M
  Fix: Add `Relation::enforceMorphMap([...])` in service provider `boot()`.

- [ ] `docker-compose.yml:19` — Hardcoded database credentials — Effort: S
  Fix: Use `${DB_PASSWORD:-password}` environment variable interpolation; add `.env` template.

- [ ] `src/Models/Account.php:114` — isDebitNormal() silently returns true for null type — Effort: S
  Fix: Throw `LogicException` when type is null; enforce non-nullable column in migration.

- [ ] `src/Models/LedgerEntry.php:63` — Missing account silently treated as credit-normal — Effort: S
  Fix: Throw exception when `Account::find()` returns null in the creating hook.

- [ ] `src/Services/Reports/AgingReport.php:85` — Absolute date diff miscategorizes future entries — Effort: S
  Fix: Use signed `diffInDays($date, false)` and skip negative-difference entries.

- [ ] `src/Models/Account.php:88` — balance attribute vs getBalance() silent divergence — Effort: S
  Fix: Rename accessor to `getCachedBalanceAttribute()`.

- [ ] `src/Services/Reports/BalanceSheet.php:30` — BalanceSheet tightly coupled to IncomeStatement — Effort: M
  Fix: Extract `NetIncomeCalculator` service.

- [ ] `src/Services/Reports/BalanceSheet.php:21` — Report classes all-static (no DI) — Effort: XL
  Fix: Convert to instantiable services with constructor injection.

- [ ] `src/Services/Reports/IncomeStatement.php:39` — Debit/credit aggregation logic duplicated 8+ times — Effort: L
  Fix: Create shared `AccountBalanceQuery` service.

- [ ] `src/Models/LedgerEntry.php:129` — referencesModel() deprecated dead code — Effort: S
  Fix: Delete the method.

- [ ] `config/accounting.php:6` — Unused audit configuration block — Effort: S
  Fix: Remove `audit` block from config.

- [ ] `package-lock.json:189` — js-yaml CVE (ReDoS) — Effort: S
  Fix: Update `gray-matter` to a version depending on `js-yaml` v4.x; add `npm audit` to CI.

- [ ] `composer.json:19` — moneyphp/money on unmaintained v3.x — Effort: M
  Fix: Update constraint to `^4.0`; run `composer update moneyphp/money`; address API changes.

- [ ] `composer.json:53` — minimum-stability set to dev — Effort: S
  Fix: Change to `stable`.

- [ ] `.github/workflows/claude-code-review.yml:1` — No security audit in CI — Effort: S
  Fix: Add `composer audit --no-dev` and `npm audit --audit-level=high` CI steps.

- [ ] `src/Models/Account.php:213` — Redundant $this->refresh() in debit()/credit() — Effort: S
  Fix: Remove `$this->refresh()` calls; manually update `cached_balance` from recalculate return value.

- [ ] `src/Models/JournalEntry.php:146` — Redundant pluck query after foreach — Effort: S
  Fix: Collect account IDs in the existing foreach loop; remove separate pluck query.

- [ ] `src/Models/Account.php:352` — recalculateBalance() fires redundantly per entry — Effort: L
  Fix: Implement deferred recalculation with `DB::afterCommit` deduplication per account.

- [ ] `src/migrations/...ledger_entries_table.php:37` — Redundant standalone account_id index — Effort: S
  Fix: Add new migration to drop the redundant standalone index.

- [ ] `src/Services/ChartOfAccountsSeeder.php:53` — TOCTOU check-then-create + N+1 in seeder — Effort: M
  Fix: Use `Account::firstOrCreate()` or bulk load existing codes before loop; add unique index on `code`.

- [ ] `tests/Unit/AccountModelTest.php:194` — Carbon mock leak in tests — Effort: S
  Fix: Move `Carbon::setTestNow(null)` to `tearDown()` in each affected test class.

- [ ] `tests/Functional/ReportsTest.php:286,307` — Carbon mock leak in aging report tests — Effort: S
  Fix: Centralize in `tearDown()` method.

- [ ] `src/Models/JournalEntry.php:130` — Various untested report paths — Effort: M
  Fix: Add tests for zero-balance exclusion, `from > to` date range, abnormal balance in TrialBalance.

- [ ] `.env.example:1` — Missing .env.example file — Effort: S
  Fix: Create `.env.example` documenting all required environment variables.

- [ ] `CHANGELOG.md:1` — No changelog — Effort: S
  Fix: Create `CHANGELOG.md` following Keep a Changelog format.

- [ ] `docs/SETUP.md:1` — No developer onboarding guide — Effort: M
  Fix: Create `docs/SETUP.md` with prerequisites, setup, database, config, and test sections.

- [ ] `src/Models/Account.php:49` — Missing PHPDoc on core model classes — Effort: S
  Fix: Add class-level PHPDoc to Account, LedgerEntry, JournalEntry, and TransactionBuilder.

### Priority 4 — Backlog (Low)

- [ ] `docker-compose.yml:12` — APP_DEBUG=true in committed compose files — Effort: S
  Fix: Add dev-only comment; use compose override files for production.

- [ ] `docker-compose.yml:13` — APP_KEY empty string — Effort: S
  Fix: Generate proper key; supply via environment variable.

- [ ] `.gitignore:1` — Root .env not in .gitignore — Effort: S
  Fix: Add `.env` and `.env.*` (excluding `.env.example`) to `.gitignore`.

- [ ] `docker/php/xdebug.ini:3` — Xdebug start_with_request=yes in Docker image — Effort: M
  Fix: Multi-stage Docker build to exclude Xdebug from production image.

- [ ] `accounting-master/src/migrations/...transactions_table.php:28` — FK constraints commented out — Effort: S
  Fix: Enable FK constraints; use in-memory SQLite for tests instead of weakening schema.

- [ ] `src/Services/TransactionBuilder.php:36` — Carbon::parse() without format validation — Effort: S
  Fix: Use `Carbon::createFromFormat('Y-m-d', $date)` with explicit format.

- [ ] `src/Models/Account.php:208` — debit()/credit() accept negative/zero amounts — Effort: S
  Fix: Add `if ($amount <= 0) throw new InvalidAmountException(...)` consistent with TransactionBuilder.

- [ ] `src/Models/Account.php:149` — Redundant getCurrentBalance() aliases — Effort: S
  Fix: Add `@deprecated` annotation; remove in next major version.

- [ ] `src/Models/Account.php:181,191` — getDebitBalanceOn() / getCreditBalanceOn() unused externally — Effort: S
  Fix: Add test coverage if public API; otherwise remove or make private.

- [ ] `src/Models/Account.php:311,321` — getDollarsDebitedOn() / getDollarsCreditedOn() should be private — Effort: S
  Fix: Change visibility to `private` or document as public API with tests.

- [ ] `composer.json:24` — Unused dev dependencies (faker, mockery) — Effort: S
  Fix: Remove `fakerphp/faker` and `mockery/mockery` from `require-dev`.

- [ ] `config/accounting.php:1` — base_currency unused; hardcoded USD — Effort: M
  Fix: Replace all `'USD'` literals with `config('accounting.base_currency', 'USD')`.

- [ ] `src/Services/Reports/IncomeStatement.php:65` — Null sub_type silently miscategorized — Effort: S
  Fix: Add explicit null check; route to 'Uncategorised' bucket or log warning.

- [ ] `src/Models/JournalEntry.php:40` — empty() fragile for UUID detection — Effort: S
  Fix: Replace with `!isset($entry->id) || $entry->id === ''`.

- [ ] `composer.json:28` — allow-plugins lists non-plugins — Effort: S
  Fix: Remove `phpunit/phpunit` and `orchestra/testbench` from `allow-plugins`.

- [ ] `composer.lock:2251` — nette/* GPL license options — Effort: S
  Fix: Legal counsel review for distribution compliance.

- [ ] `package-lock.json:165` — Dual js-yaml versions — Effort: S
  Fix: Update gray-matter to consolidate to js-yaml v4.

- [ ] `src/migrations/...accounts_table.php:34` — Missing composite index (type, is_active) — Effort: S
  Fix: Add new migration with `$table->index(['type', 'is_active'])`.

- [ ] `accounting-master/src/Models/JournalTransaction.php:22` — ref_class column limited to 64 chars — Effort: S
  Fix: Increase column to 255 characters or use morphMap aliases.

- [ ] `docs/ARCHITECTURE.md:1` — No architecture decision records — Effort: M
  Fix: Create `docs/ARCHITECTURE.md` explaining key design decisions.

- [ ] `src/Models/Account.php:251` — debitDollars()/creditDollars() fractional cent behavior untested — Effort: S
  Fix: Add boundary tests for fractional and negative dollar values.

- [ ] `src/Services/Reports/AgingReport.php:74` — Aging report treats entries not invoices as outstanding items — Effort: XL
  Fix: Redesign to group entries by `ledgerable` reference and compute net outstanding per reference.

---

## Notes on Agent Execution

All 8 agents completed successfully. The following finding overlaps were resolved during deduplication:

- **N+1 queries in report generators**: Identified by both `performance` and `code-quality` agents across BalanceSheet, IncomeStatement, and TrialBalance. Merged into single findings with the most detailed combined description; performance agent's critical severity retained.
- **Missing DB transactions in reverse()/void()**: Identified independently by `code-quality` and `architecture` agents. Merged; code-quality agent's detailed description retained.
- **Double balance recalculation**: Identified by both `performance` and `architecture` agents. Merged with performance agent's detailed description.
- **Mass-assignable fillable fields on Account**: Identified by both `security` and `architecture` agents. Merged; security agent's CWE references retained.
- **Account God Class**: Identified by `architecture` agent; corroborated by the 360-line file structure observed by multiple agents.
- **getBalance() dual SUM queries**: Identified by `performance` agent at line 134 and corroborated by `code-quality` agent analysis. Merged.
- **referencesModel() dead code**: Identified by `dead-code` agent; `src/Models/LedgerEntry.php` confirmed to contain the deprecated always-throwing method.
- **Unused config/accounting.php audit block**: Identified by both `dead-code` and `architecture` agents. Merged.
- **getCurrentBalance() redundant alias**: Identified by both `dead-code` and `architecture` agents. Merged.

---

*Report synthesized from 8 parallel specialist agents: security, code-quality, performance, dead-code, architecture, test-coverage, dependencies, documentation.*
