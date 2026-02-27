# Laravel Accounting

A double-entry accounting package for Laravel. Built on proper accounting principles with immutable ledger entries, running balances, draft transaction support, and financial reporting.

## Contents

- [Requirements](#requirements)
- [Installation](#installation)
- [Core Concepts](#core-concepts)
- [Chart of Accounts](#chart-of-accounts)
- [Attaching Accounts to Models](#attaching-accounts-to-models)
- [Recording Transactions](#recording-transactions)
- [Double-Entry Transactions](#double-entry-transactions)
- [Draft Transactions](#draft-transactions)
- [Journal Entries](#journal-entries)
- [Reversals and Voids](#reversals-and-voids)
- [Financial Reports](#financial-reports)
- [Immutability](#immutability)
- [API Reference](#api-reference)
- [Exceptions](#exceptions)
- [Testing](#testing)

---

## Requirements

| Requirement | Version |
|-------------|---------|
| PHP | 8.2+ |
| Laravel | 12.x |
| Database | PostgreSQL, MySQL, SQLite, or SQL Server |

---

## Installation

### 1. Install via Composer

```bash
composer require wayum999/laravel-accounting
```

The service provider is automatically discovered. No manual registration is required.

### 2. Publish and Run Migrations

```bash
php artisan vendor:publish --provider="App\Accounting\Providers\AccountingServiceProvider"
php artisan migrate
```

This creates the following tables:

| Table | Purpose |
|-------|---------|
| `accounting_accounts` | Chart of accounts with type, sub-type, and polymorphic ownership |
| `accounting_journal_entries` | Groups of balanced ledger entries (UUID primary key) |
| `accounting_ledger_entries` | Individual debit/credit entries with running balances |

---

## Core Concepts

### Double-Entry Accounting

Every financial transaction is recorded as at least one debit and one credit of equal amounts. The `TransactionBuilder` enforces this rule and throws `UnbalancedTransactionException` if you attempt to commit an unbalanced transaction.

### The Five Account Types

| Type | Balance Type | Increases With | Examples |
|------|---------------|----------------|---------|
| `ASSET` | Debit Balance | Debit | Cash, Accounts Receivable, Inventory |
| `LIABILITY` | Credit Balance | Credit | Accounts Payable, Loans Payable |
| `EQUITY` | Credit Balance | Credit | Owner's Equity, Retained Earnings |
| `INCOME` | Credit Balance | Credit | Sales Revenue, Service Fees |
| `EXPENSE` | Debit Balance | Debit | Salaries, Rent, Cost of Goods Sold |

### Account Sub-Types

Accounts are further classified by sub-type, following the QuickBooks model. Sub-types control how accounts are grouped in financial reports:

| Parent Type | Sub-Types |
|-------------|-----------|
| Asset | `BANK`, `ACCOUNTS_RECEIVABLE`, `OTHER_CURRENT_ASSET`, `INVENTORY`, `FIXED_ASSET`, `OTHER_ASSET` |
| Liability | `ACCOUNTS_PAYABLE`, `CREDIT_CARD`, `OTHER_CURRENT_LIABILITY`, `LONG_TERM_LIABILITY` |
| Equity | `OWNERS_EQUITY`, `RETAINED_EARNINGS` |
| Income | `REVENUE`, `OTHER_INCOME` |
| Expense | `COST_OF_GOODS_SOLD`, `OPERATING_EXPENSE`, `OTHER_EXPENSE` |

```php
use App\Accounting\Enums\AccountType;
use App\Accounting\Enums\AccountSubType;

// Get all sub-types for a given type
$assetSubTypes = AccountSubType::forType(AccountType::ASSET);

// Sub-type metadata
AccountSubType::BANK->parentType();   // AccountType::ASSET
AccountSubType::BANK->reportGroup();  // "Current Assets"
AccountSubType::BANK->isCurrent();    // true
AccountSubType::BANK->label();        // "Bank"
```

### Money Handling

All monetary amounts are stored as **integer cents** to avoid floating-point rounding errors. This package uses [moneyphp/money](https://github.com/moneyphp/money) internally. Dollar convenience methods accept float values and handle the conversion automatically.

### Immutable Ledger

Ledger entries are **immutable after creation**. They cannot be updated or deleted. This is standard accounting practice — to correct an error, create a reversing journal entry. See [Immutability](#immutability) for details.

### Running Balances

Each posted ledger entry stores a `running_balance` computed at insert time. This represents the cumulative account balance after that entry, respecting the account's normal balance direction.

---

## Chart of Accounts

### Using the Seeder

The `ChartOfAccountsSeeder` provides three built-in templates:

```php
use App\Accounting\Services\ChartOfAccountsSeeder;

// Minimal template (5 core accounts)
ChartOfAccountsSeeder::seed();

// Service business template (12 accounts)
ChartOfAccountsSeeder::seed('service');

// Retail business template (14 accounts, includes inventory and COGS)
ChartOfAccountsSeeder::seed('retail');

// Custom currency
ChartOfAccountsSeeder::seed('minimal', 'EUR');
```

The seeder is idempotent — running it again will not create duplicate accounts.

### Creating Accounts Manually

```php
use App\Accounting\Models\Account;
use App\Accounting\Enums\AccountType;
use App\Accounting\Enums\AccountSubType;

$cash = Account::create([
    'name' => 'Operating Cash',
    'code' => '1000',
    'type' => AccountType::ASSET,
    'sub_type' => AccountSubType::BANK,
    'currency' => 'USD',
]);

$revenue = Account::create([
    'name' => 'Sales Revenue',
    'code' => '4000',
    'type' => AccountType::INCOME,
    'sub_type' => AccountSubType::REVENUE,
    'currency' => 'USD',
]);
```

### Parent-Child Accounts

Accounts support hierarchical nesting via `parent_id`:

```php
$parentAsset = Account::create([
    'name' => 'Current Assets',
    'code' => '1000',
    'type' => AccountType::ASSET,
]);

$cash = Account::create([
    'name' => 'Cash',
    'code' => '1010',
    'type' => AccountType::ASSET,
    'sub_type' => AccountSubType::BANK,
    'parent_id' => $parentAsset->id,
]);

$parentAsset->children; // Collection of child accounts
$cash->parent;          // The parent account
```

### Custom Templates

```php
use App\Accounting\Enums\AccountSubType;
use App\Accounting\Enums\AccountType;

ChartOfAccountsSeeder::seedFromTemplate([
    [
        'name' => 'Operating Cash',
        'code' => '1000',
        'type' => AccountType::ASSET,
        'sub_type' => AccountSubType::BANK,
    ],
    [
        'name' => 'Client Revenue',
        'code' => '4000',
        'type' => AccountType::INCOME,
        'sub_type' => AccountSubType::REVENUE,
    ],
]);
```

---

## Attaching Accounts to Models

Any Eloquent model can own accounting accounts via the `HasAccounting` trait.

```php
use App\Accounting\Traits\HasAccounting;

class Customer extends Model
{
    use HasAccounting;
}
```

### Creating Accounts for a Model

```php
$customer = Customer::find(1);

$account = $customer->createAccount(
    name: 'Accounts Receivable',
    type: AccountType::ASSET,
    code: 'AR-001',
    currency: 'USD',
    subType: AccountSubType::ACCOUNTS_RECEIVABLE,
);
```

### Retrieving Accounts

```php
// Get all accounts
$customer->accounts();

// Get a specific account by name
$customer->account('Accounts Receivable');

// Get the first account (or null)
$customer->account();
```

> Calling `createAccount` with a duplicate name on the same model throws `AccountAlreadyExistsException`.

---

## Recording Transactions

### TransactionBuilder (Recommended)

The `TransactionBuilder` is the primary way to record transactions. It enforces double-entry balance, wraps everything in a database transaction, and creates a `JournalEntry` with linked `LedgerEntry` records.

```php
use App\Accounting\Services\TransactionBuilder;

// Record a sale: debit Cash, credit Revenue
$journalEntry = TransactionBuilder::create()
    ->date('2025-01-15')
    ->memo('Invoice #1042')
    ->reference('INV-1042')
    ->debit($cash, 120000)      // $1,200.00 in cents
    ->credit($revenue, 120000)
    ->commit();
```

### Dollar Amounts

```php
$journalEntry = TransactionBuilder::create()
    ->debitDollars($cash, 1200.00)
    ->creditDollars($revenue, 1200.00)
    ->commit();
```

### Money Objects

```php
use Money\Money;
use Money\Currency;

$amount = new Money(150000, new Currency('EUR'));

$journalEntry = TransactionBuilder::create()
    ->debit($euroCash, $amount)
    ->credit($euroRevenue, $amount)
    ->commit();
```

### Multi-Line Transactions

A single transaction can have any number of entries as long as total debits equal total credits:

```php
// Purchase equipment: pay some cash, put the rest on credit
$journalEntry = TransactionBuilder::create()
    ->memo('Office server purchase')
    ->debit($equipment, 300000)       // $3,000 asset increase
    ->credit($cash, 100000)           // $1,000 cash payment
    ->credit($accountsPayable, 200000) // $2,000 on account
    ->commit();
```

### Increase and Decrease

When you don't want to think about debits and credits, use `increase()` and `decrease()`. These auto-select the correct side based on the account type:

```php
$journalEntry = TransactionBuilder::create()
    ->increase($cash, 50000)        // Asset → debit
    ->increase($revenue, 50000)     // Income → credit
    ->commit();

$journalEntry = TransactionBuilder::create()
    ->decrease($cash, 20000)        // Asset → credit
    ->increase($expense, 20000)     // Expense → debit
    ->commit();
```

| Account Type | `increase()` | `decrease()` |
|-------------|-------------|-------------|
| Asset, Expense (debit balance) | Debit | Credit |
| Liability, Equity, Income (credit balance) | Credit | Debit |

### Referencing Models

Attach any Eloquent model to individual entries via the polymorphic `ledgerable` relationship:

```php
$invoice = Invoice::find(42);

$journalEntry = TransactionBuilder::create()
    ->debit($accountsReceivable, 120000, 'Invoice payment', $invoice)
    ->credit($revenue, 120000)
    ->commit();
```

### Per-Entry Memos

Each entry can have its own memo. Entries without a memo inherit the transaction-level memo:

```php
$journalEntry = TransactionBuilder::create()
    ->memo('Monthly payroll')
    ->debit($salaryExpense, 500000, 'Salary - John')
    ->debit($salaryExpense, 450000, 'Salary - Jane')
    ->credit($cash, 950000) // inherits "Monthly payroll"
    ->commit();
```

### Inspecting Pending Entries

```php
$builder = TransactionBuilder::create()
    ->debit($cash, 5000)
    ->credit($revenue, 5000);

$pending = $builder->getPendingEntries();
// Array of ['account' => ..., 'debit' => ..., 'credit' => ..., ...]
```

### Standalone Account Methods

For quick one-off entries (outside the TransactionBuilder), accounts have convenience methods:

```php
// Amount in cents
$cash->debit(50000, 'Equipment purchase');
$cash->credit(50000, 'Customer payment');

// Amount in dollars
$cash->debitDollars(500.00, 'Equipment purchase');
$cash->creditDollars(500.00, 'Customer payment');

// Auto-select debit/credit
$cash->increase(50000, 'Cash received');
$cash->decrease(20000, 'Cash paid');
$cash->increaseDollars(500.00);
$cash->decreaseDollars(200.00);

// With a reference model
$cash->debit(50000, 'Payment', now(), $invoice);

// With a specific post date
$cash->debit(50000, 'Backdated entry', Carbon::parse('2024-01-15'));
```

> **Note:** Standalone methods create single-sided entries. Use `TransactionBuilder` for proper double-entry transactions.

---

## Double-Entry Transactions

The `TransactionBuilder` creates a `JournalEntry` (the header) with linked `LedgerEntry` records (the lines). Each journal entry has a UUID primary key.

```
JournalEntry (UUID)
├── LedgerEntry: Cash          DR 1,200.00
└── LedgerEntry: Revenue                    CR 1,200.00
```

### Checking Balances

```php
// Current balance (Money object, in cents)
$balance = $account->getBalance();

// Balance in dollars
$dollars = $account->getBalanceInDollars();

// Balance as of a specific date
$balance = $account->getBalanceOn(Carbon::parse('2024-06-30'));

// Cached balance (from the `cached_balance` column, auto-maintained)
$balance = $account->balance; // Money object

// Daily activity
$debited  = $account->getDollarsDebitedToday();
$credited = $account->getDollarsCreditedToday();
$debited  = $account->getDollarsDebitedOn(Carbon::parse('2024-06-30'));
$credited = $account->getDollarsCreditedOn(Carbon::parse('2024-06-30'));
```

All balance methods automatically exclude unposted (draft) entries.

---

## Draft Transactions

Draft transactions allow you to record entries without affecting account balances or financial reports. This is useful for pending invoices, unapproved expenses, or any transaction that needs review before posting.

### Creating a Draft

```php
$journalEntry = TransactionBuilder::create()
    ->draft()
    ->memo('Pending invoice #2001')
    ->debit($accountsReceivable, 250000)
    ->credit($revenue, 250000)
    ->commit();

$journalEntry->is_posted; // false
```

Draft entries:
- Have `is_posted = false` on both the JournalEntry and its LedgerEntries
- Do **not** affect account balances (`cached_balance` is unchanged)
- Do **not** appear in financial reports (TrialBalance, BalanceSheet, etc.)
- Have a `running_balance` of `0`

### Posting a Draft

```php
$journalEntry->post();

$journalEntry->is_posted; // true
// Account balances and running balances are now computed
```

Posting recalculates the `running_balance` for each ledger entry and updates the affected accounts' `cached_balance`.

### Unposting a Transaction

```php
$journalEntry->unpost();

$journalEntry->is_posted; // false
// Account balances are recalculated to exclude these entries
```

Both `post()` and `unpost()` are idempotent — calling them when already in that state is a no-op.

---

## Journal Entries

### Structure

```php
use App\Accounting\Models\JournalEntry;

$je = JournalEntry::create([
    'date' => '2025-01-15',
    'reference_number' => 'INV-001',
    'memo' => 'January sale',
]);

// Access ledger entries
$je->ledgerEntries;

// Balance checks
$je->totalDebits();   // Sum of all debit amounts
$je->totalCredits();  // Sum of all credit amounts
$je->isBalanced();    // true if debits == credits
```

---

## Reversals and Voids

Ledger entries are immutable — you cannot edit or delete them. Instead, use reversals and voids to correct errors. Both create new journal entries with swapped debits and credits.

### Reversing a Journal Entry

Creates a new journal entry with debits and credits swapped, dated today:

```php
$reversal = $journalEntry->reverse('Correcting entry for INV-001');

// Net effect on all accounts is zero
$cash->refresh();
$cash->getBalanceInDollars(); // Back to original
```

### Voiding a Journal Entry

Creates a reversal using the **original date** and prefixes the memo with `VOID:`:

```php
$void = $journalEntry->void();

$void->memo;              // "VOID: January sale"
$void->date->toDateString(); // Same date as original
```

> Both `reverse()` and `void()` throw `LogicException` if called on an unposted journal entry.

---

## Financial Reports

All reports automatically exclude unposted (draft) entries.

### Trial Balance

```php
use App\Accounting\Services\Reports\TrialBalance;

$report = TrialBalance::generate(
    asOf: Carbon::parse('2025-01-31'),
    currency: 'USD',
    includeZeroBalances: false, // default
);

// $report = [
//     'accounts' => [
//         ['account_id' => 1, 'code' => '1000', 'name' => 'Cash', 'type' => 'asset',
//          'sub_type' => AccountSubType::BANK, 'debit' => 50000, 'credit' => 0],
//         ...
//     ],
//     'total_debits' => 150000,
//     'total_credits' => 150000,   // Always balanced
//     'is_balanced' => true,
//     'as_of' => '2025-01-31',
//     'currency' => 'USD',
// ]
```

### Income Statement (Profit & Loss)

Separates income and expenses into categories: Revenue, COGS, Operating Expenses, and Other Income/Expenses. Computes gross profit and operating income.

```php
use App\Accounting\Services\Reports\IncomeStatement;

$report = IncomeStatement::generate(
    from: Carbon::parse('2025-01-01'),
    to: Carbon::parse('2025-12-31'),
);

// Detailed structure
$report['revenue'];             // Revenue accounts
$report['cost_of_goods_sold'];  // COGS accounts
$report['gross_profit'];        // Revenue - COGS
$report['operating_expenses'];  // Operating expense accounts
$report['operating_income'];    // Gross Profit - Operating Expenses
$report['other_income'];        // Other income accounts
$report['other_expenses'];      // Other expense accounts
$report['net_income'];          // Total Income - Total Expenses

// Backward-compatible flat arrays
$report['income'];              // All income accounts
$report['expenses'];            // All expense accounts
$report['total_income'];
$report['total_expenses'];
```

### Balance Sheet

Computes assets, liabilities, equity, and net income. Groups accounts by sub-type (Current Assets, Fixed Assets, Current Liabilities, etc.).

```php
use App\Accounting\Services\Reports\BalanceSheet;

$report = BalanceSheet::generate(
    asOf: Carbon::parse('2025-12-31'),
);

// Grouped by sub-type
$report['grouped_assets'];      // ['Current Assets' => [...], 'Fixed Assets' => [...]]
$report['grouped_liabilities']; // ['Current Liabilities' => [...], 'Long-Term Liabilities' => [...]]
$report['grouped_equity'];      // ['Equity' => [...]]

// Flat arrays (backward-compatible)
$report['assets'];
$report['liabilities'];
$report['equity'];

// Totals
$report['total_assets'];
$report['total_liabilities'];
$report['total_equity'];
$report['net_income'];           // From IncomeStatement for the period
$report['is_balanced'];          // Assets == Liabilities + Equity + Net Income
```

### Cash Flow Statement

Direct method cash flow statement, categorized by operating, investing, and financing activities based on contra-account types:

```php
use App\Accounting\Services\Reports\CashFlowStatement;

$report = CashFlowStatement::generate(
    from: Carbon::parse('2025-01-01'),
    to: Carbon::parse('2025-12-31'),
    cashAccount: null,   // null = all bank-type accounts
    currency: 'USD',
);

$report['operating'];         // Cash flows from income/expense accounts
$report['investing'];         // Cash flows from asset accounts
$report['financing'];         // Cash flows from liability/equity accounts
$report['total_operating'];
$report['total_investing'];
$report['total_financing'];
$report['net_cash_flow'];
$report['beginning_balance'];
$report['ending_balance'];
```

### Aging Report

Categorizes receivables or payables into aging buckets:

```php
use App\Accounting\Services\Reports\AgingReport;

$report = AgingReport::generate(
    type: AccountType::ASSET, // AR aging; use LIABILITY for AP
    asOf: Carbon::now(),
);

// $report['details'] = [
//     ['account_id' => 1, 'name' => 'AR - Customer A', 'total' => 50000,
//      'buckets' => [
//          ['label' => 'Current (0-30)', 'amount' => 30000],
//          ['label' => '31-60', 'amount' => 20000],
//          ...
//      ]],
// ]
// $report['summary'] = [['label' => 'Current (0-30)', 'amount' => ...], ...]
// $report['total_outstanding'] = 50000

// Custom buckets
$report = AgingReport::generate(
    type: AccountType::ASSET,
    customBuckets: [
        ['label' => '0-15 days', 'min' => 0, 'max' => 15],
        ['label' => '16-45 days', 'min' => 16, 'max' => 45],
        ['label' => '46+ days', 'min' => 46, 'max' => null],
    ],
);
```

---

## Immutability

Ledger entries are immutable — they cannot be updated or deleted after creation. This is standard accounting practice to maintain a complete audit trail.

### What's Enforced

```php
// Throws ImmutableEntryException
$entry->memo = 'Changed';
$entry->save();

// Throws ImmutableEntryException
$entry->delete();
```

### What's Allowed

The `is_posted` flag can be changed via `JournalEntry::post()` and `JournalEntry::unpost()`. No other fields can be modified.

### How to Correct Errors

```php
// Wrong: trying to edit an entry
$entry->debit = 5000; // throws ImmutableEntryException

// Right: create a reversing entry
$reversal = $journalEntry->reverse('Correcting error');

// Then record the correct transaction
$corrected = TransactionBuilder::create()
    ->memo('Corrected entry')
    ->debit($cash, 5000)
    ->credit($revenue, 5000)
    ->commit();
```

### Database-Level Protection

The `account_id` foreign key on ledger entries uses `RESTRICT` on delete — an account cannot be deleted if it has ledger entries. Accounts use soft deletes, so `$account->delete()` sets `deleted_at` without triggering the FK constraint.

---

## API Reference

### Account

| Method | Returns | Description |
|--------|---------|-------------|
| `getBalance()` | `Money` | Live-calculated balance from all posted entries |
| `getBalanceInDollars()` | `float` | Balance in dollars |
| `getCurrentBalance()` | `Money` | Alias for `getBalance()` |
| `getBalanceOn(Carbon $date)` | `Money` | Balance as of a specific date |
| `debit(int\|Money $amount, ?string $memo, ?Carbon $postDate, ?Model $reference)` | `LedgerEntry` | Post a debit entry |
| `credit(int\|Money $amount, ?string $memo, ?Carbon $postDate, ?Model $reference)` | `LedgerEntry` | Post a credit entry |
| `debitDollars(float $dollars, ?string $memo, ?Carbon $postDate)` | `LedgerEntry` | Post a debit in dollars |
| `creditDollars(float $dollars, ?string $memo, ?Carbon $postDate)` | `LedgerEntry` | Post a credit in dollars |
| `increase(int $amount, ?string $memo, ?Carbon $postDate)` | `LedgerEntry` | Increase balance (auto debit/credit) |
| `decrease(int $amount, ?string $memo, ?Carbon $postDate)` | `LedgerEntry` | Decrease balance (auto debit/credit) |
| `recalculateBalance()` | `Money` | Recompute `cached_balance` from ledger entries |
| `getDollarsDebitedOn(Carbon $date)` | `float` | Total debits on a date |
| `getDollarsCreditedOn(Carbon $date)` | `float` | Total credits on a date |
| `entriesReferencingModel(Model $model)` | `HasMany` | Ledger entries linked to a model |
| `isDebitNormal()` | `bool` | True for debit balance accounts (Asset, Expense) |

### TransactionBuilder

| Method | Returns | Description |
|--------|---------|-------------|
| `TransactionBuilder::create()` | `self` | New builder instance |
| `date(Carbon\|string $date)` | `self` | Set the transaction date |
| `memo(string $memo)` | `self` | Set the transaction memo |
| `reference(string $ref)` | `self` | Set the reference number |
| `draft()` | `self` | Mark as draft (unposted) |
| `debit(Account, int\|Money, ?string, ?Model)` | `self` | Add a debit entry |
| `credit(Account, int\|Money, ?string, ?Model)` | `self` | Add a credit entry |
| `increase(Account, int\|Money, ?string, ?Model)` | `self` | Increase account (auto debit/credit) |
| `decrease(Account, int\|Money, ?string, ?Model)` | `self` | Decrease account (auto debit/credit) |
| `debitDollars(Account, float, ?string, ?Model)` | `self` | Add a debit in dollars |
| `creditDollars(Account, float, ?string, ?Model)` | `self` | Add a credit in dollars |
| `getPendingEntries()` | `array` | Inspect entries before committing |
| `commit()` | `JournalEntry` | Validate balance and persist |

### JournalEntry

| Method | Returns | Description |
|--------|---------|-------------|
| `totalDebits()` | `int` | Sum of all debit amounts |
| `totalCredits()` | `int` | Sum of all credit amounts |
| `isBalanced()` | `bool` | True if debits == credits |
| `post()` | `self` | Post the journal entry and all ledger entries |
| `unpost()` | `self` | Unpost the journal entry and all ledger entries |
| `reverse(?string $memo)` | `JournalEntry` | Create a reversing entry (today's date) |
| `void()` | `JournalEntry` | Create a voiding entry (original date) |

### HasAccounting Trait

| Method | Returns | Description |
|--------|---------|-------------|
| `accounts()` | `MorphMany` | All accounting accounts for this model |
| `account(?string $name)` | `?Account` | Get account by name, or first account |
| `createAccount(string $name, AccountType, ?string $code, string $currency, ?AccountSubType)` | `Account` | Create a new account |

### AccountType Enum

| Method | Returns | Description |
|--------|---------|-------------|
| `isDebitNormal()` | `bool` | True for debit balance types (ASSET, EXPENSE) |
| `isCreditNormal()` | `bool` | True for credit balance types (LIABILITY, EQUITY, INCOME) |
| `balanceSign()` | `int` | `1` for debit balance, `-1` for credit balance |
| `label()` | `string` | Human-readable label |
| `values()` | `array` | All enum string values |

### AccountSubType Enum

| Method | Returns | Description |
|--------|---------|-------------|
| `parentType()` | `AccountType` | The parent account type |
| `reportGroup()` | `string` | Report section label (e.g., "Current Assets") |
| `isCurrent()` | `bool` | Whether this is a current (short-term) item |
| `label()` | `string` | Human-readable label |
| `forType(AccountType)` | `array` | All sub-types for a given type |

---

## Exceptions

| Exception | Thrown When |
|-----------|-------------|
| `UnbalancedTransactionException` | `TransactionBuilder::commit()` is called with unequal debits and credits |
| `InvalidAmountException` | An entry with an amount of zero or less is added |
| `InvalidEntryMethodException` | A method other than `'debit'` or `'credit'` is used internally |
| `ImmutableEntryException` | A ledger entry is updated or deleted |
| `AccountAlreadyExistsException` | `createAccount()` is called with a duplicate name on the same model |

---

## Testing

### With Docker

```bash
# Start the test environment
make up

# Run the full test suite with coverage
make test

# Run tests without coverage
make test-fast

# Stop the environment
make down
```

### Without Docker

```bash
composer test
```

### Test Suite

The test suite includes 119 tests across unit, functional, and complex use-case categories:

- **Unit tests** — Account, JournalEntry, LedgerEntry models, enums, exceptions
- **Functional tests** — TransactionBuilder, ChartOfAccountsSeeder, HasAccounting trait, all reports
- **Complex use cases** — Full company lifecycle, reversals and voids, multi-currency, polymorphic ownership

---

## License

This package is open-sourced software licensed under the [MIT license](LICENSE).
