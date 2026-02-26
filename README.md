# Laravel Accounting

A double-entry accounting package for Laravel. Built on proper accounting principles — correct debit/credit behavior, a General Journal, a General Ledger, and non-posting transaction support for quotes, proposals, and purchase orders.

## Contents

- [Requirements](#requirements)
- [Installation](#installation)
- [Core Concepts](#core-concepts)
- [Chart of Accounts Setup](#chart-of-accounts-setup)
- [Attaching Accounts to Models](#attaching-accounts-to-models)
- [Recording Transactions](#recording-transactions)
- [Double-Entry Transactions](#double-entry-transactions)
- [Increase and Decrease Convenience Methods](#increase-and-decrease-convenience-methods)
- [General Journal](#general-journal)
- [General Ledger](#general-ledger)
- [Non-Posting Transactions](#non-posting-transactions)
- [API Reference](#api-reference)
- [Exceptions](#exceptions)
- [Testing](#testing)

---

## Requirements

| Requirement | Version |
|-------------|---------|
| PHP | 8.1, 8.2, or 8.3 |
| Laravel | 8.x, 9.x, 10.x, 11.x, or 12.x |
| Database | MySQL, PostgreSQL, SQLite, or SQL Server |

---

## Installation

### 1. Install via Composer

```bash
composer require williamlettieri/accounting
```

The service provider is automatically discovered. No manual registration is required.

### 2. Publish Migrations

```bash
php artisan vendor:publish --provider="App\Accounting\Providers\AccountingServiceProvider"
```

This publishes five migrations that create the following tables:

| Table | Purpose |
|-------|---------|
| `accounting_account_types` | Account categories (chart of accounts) |
| `accounting_accounts` | Individual accounts, polymorphically linked to any model |
| `accounting_journal_entries` | Individual debit/credit entries |
| `accounting_non_posting_transactions` | Quotes, proposals, purchase orders, etc. |
| `accounting_non_posting_line_items` | Line items for non-posting transactions |

### 3. Run Migrations

```bash
php artisan migrate
```

### 4. Add the Trait to Your Models

Add `HasAccount` to any Eloquent model that needs an accounting account:

```php
use App\Accounting\ModelTraits\HasAccount;

class Customer extends Model
{
    use HasAccount;
}
```

---

## Core Concepts

### Double-Entry Accounting

Every financial transaction is recorded as at least one debit and one credit of equal amounts. The `Transaction` service enforces this rule — it will throw `DebitsAndCreditsDoNotEqual` if you attempt to commit an unbalanced transaction.

### The Five Account Categories

This package follows the QuickBooks model with five fundamental account categories defined by the `AccountCategory` enum:

| Category | Normal Balance | Increases With | Examples |
|----------|---------------|----------------|---------|
| `ASSET` | Debit | Debit | Cash, Accounts Receivable, Inventory |
| `LIABILITY` | Credit | Credit | Accounts Payable, Loans Payable |
| `EQUITY` | Credit | Credit | Common Stock, Retained Earnings |
| `INCOME` | Credit | Credit | Sales Revenue, Service Fees |
| `EXPENSE` | Debit | Debit | Salaries, Rent, Cost of Goods Sold |

### Sign Convention

The `Account::getBalance()` method always returns a **positive value** when the account is in its normal balance state:

- An asset account with more debits than credits returns a positive balance.
- An income account with more credits than debits returns a positive balance.

This means you can always compare balances directly without accounting for sign conventions in application code.

### Money Handling

All monetary amounts are stored in the database as **integer cents** to avoid floating-point rounding errors. This package uses [moneyphp/money](https://github.com/moneyphp/money) internally. Dollar convenience methods (`creditDollars`, `debitDollars`, `increaseDollars`, `decreaseDollars`) accept standard float values and handle the conversion automatically.

---

## Chart of Accounts Setup

`AccountType` represents a node in your chart of accounts. Each account type is assigned one of the five `AccountCategory` values and can be nested via `parent_id` for sub-categories.

```php
use App\Accounting\Models\AccountType;
use App\Accounting\Enums\AccountCategory;

// Top-level asset account type
$currentAssets = AccountType::create([
    'name'        => 'Current Assets',
    'type'        => AccountCategory::ASSET,
    'code'        => '1000',
    'description' => 'Short-term assets convertible to cash within one year',
    'is_active'   => true,
]);

// Child account type under Current Assets
$cash = AccountType::create([
    'name'      => 'Cash and Cash Equivalents',
    'type'      => AccountCategory::ASSET,
    'code'      => '1010',
    'parent_id' => $currentAssets->id,
    'is_active' => true,
]);

// Liability account type
$currentLiabilities = AccountType::create([
    'name'      => 'Current Liabilities',
    'type'      => AccountCategory::LIABILITY,
    'code'      => '2000',
    'is_active' => true,
]);

// Income account type
$salesIncome = AccountType::create([
    'name'      => 'Sales Income',
    'type'      => AccountCategory::INCOME,
    'code'      => '4000',
    'is_active' => true,
]);

// Expense account type
$operatingExpenses = AccountType::create([
    'name'      => 'Operating Expenses',
    'type'      => AccountCategory::EXPENSE,
    'code'      => '5000',
    'is_active' => true,
]);
```

Account types support hierarchical relationships:

```php
// Get parent type
$currentAssets->parent;

// Get child types
$currentAssets->children;

// Get all accounts under this type
$currentAssets->accounts;

// Get aggregate balance across all accounts in this type
$totalAssets = $currentAssets->getCurrentBalance('USD');
$totalDollars = $currentAssets->getCurrentBalanceInDollars();
```

---

## Attaching Accounts to Models

Any Eloquent model with the `HasAccount` trait can have one polymorphic accounting account.

### Setting Up the Trait

```php
use App\Accounting\ModelTraits\HasAccount;

class Customer extends Model
{
    use HasAccount;

    protected static function boot(): void
    {
        parent::boot();

        // Initialize an account automatically when a customer is created
        static::created(function (Customer $customer): void {
            $customer->initAccount('USD', $accountsReceivableType->id);
        });
    }
}
```

### Initializing an Account

```php
// Initialize with currency and account type
$customer->initAccount('USD', $accountsReceivableType->id);

// Initialize with currency only (no account type assigned)
$customer->initAccount('USD');

// Access the account
$account = $customer->account;
```

> **Note:** Calling `initAccount` on a model that already has an account throws `AccountAlreadyExists`.

### Standalone Accounts

You can also create accounts that are not attached to a model:

```php
use App\Accounting\Models\Account;

$cashAccount = Account::create([
    'name'            => 'Operating Cash',
    'number'          => '1010-001',
    'account_type_id' => $cashType->id,
    'currency'        => 'USD',
    'is_active'       => true,
]);
```

---

## Recording Transactions

### Raw Debit and Credit Methods

Use `debit()` and `credit()` when you need explicit control over which side of the ledger an entry goes on. Amounts are in cents.

```php
// Record a debit (amount in cents)
$account->debit(50000, 'Equipment purchase');

// Record a credit (amount in cents)
$account->credit(50000, 'Customer payment received');

// Dollar convenience methods
$account->debitDollars(500.00, 'Equipment purchase');
$account->creditDollars(500.00, 'Customer payment received');

// With an explicit post date
$account->debitDollars(500.00, 'Backdated entry', Carbon::parse('2024-01-15'));
```

### Checking Balances

```php
// Money object (amount in cents internally)
$balance = $account->getBalance();

// Current balance as a float in dollars
$dollars = $account->getBalanceInDollars();    // Uses all entries
$dollars = $account->getCurrentBalanceInDollars(); // As of now

// Balance as of a specific date
$historicBalance = $account->getBalanceOn(Carbon::parse('2024-06-30'));

// Daily activity totals
$debitedToday  = $account->getDollarsDebitedToday();
$creditedToday = $account->getDollarsCreditedToday();

// Activity on a specific date
$debitedOn  = $account->getDollarsDebitedOn(Carbon::parse('2024-06-30'));
$creditedOn = $account->getDollarsCreditedOn(Carbon::parse('2024-06-30'));
```

---

## Double-Entry Transactions

The `Transaction` class enforces the fundamental accounting rule that total debits must equal total credits. It wraps all entries in a database transaction and assigns a shared UUID (`transaction_group`) to each set of entries so they can be retrieved together.

```php
use App\Accounting\Transaction;

// A sale: debit Accounts Receivable, credit Sales Income
$group = Transaction::newDoubleEntryTransactionGroup();

$group->addDollarTransaction(
    account: $accountsReceivableAccount,
    method:  'debit',
    value:   1200.00,
    memo:    'Invoice #1042 - Web development services'
);

$group->addDollarTransaction(
    account: $salesIncomeAccount,
    method:  'credit',
    value:   1200.00,
    memo:    'Invoice #1042 - Web development services'
);

// Returns the transaction_group UUID on success.
// Throws DebitsAndCreditsDoNotEqual if amounts do not balance.
$transactionGroupId = $group->commit();
```

### Referencing an Eloquent Model

You can attach any Eloquent model to an individual journal entry:

```php
$invoice = Invoice::find(42);

$group->addDollarTransaction(
    account:          $accountsReceivableAccount,
    method:           'debit',
    value:            1200.00,
    memo:             'Invoice payment',
    referencedObject: $invoice  // Optional polymorphic reference
);
```

### Multi-Line Transactions

A single transaction group can contain any number of entries as long as total debits equal total credits:

```php
// Record a purchase: pay with cash and put the remainder on credit
$group = Transaction::newDoubleEntryTransactionGroup();

// Increase the asset (equipment)
$group->addDollarTransaction($equipmentAccount, 'debit', 3000.00, 'Office server');

// Decrease cash (asset decreases with a credit)
$group->addDollarTransaction($cashAccount, 'credit', 1000.00, 'Cash payment');

// Increase accounts payable (liability increases with a credit)
$group->addDollarTransaction($accountsPayableAccount, 'credit', 2000.00, 'On account');

$group->commit(); // 3000 debit == 1000 + 2000 credit. Passes.
```

### Using Money Objects Directly

For non-USD currencies, use `addTransaction()` with a `Money` object:

```php
use Money\Money;
use Money\Currency;

$group->addTransaction(
    account: $euroCashAccount,
    method:  'debit',
    money:   new Money(150000, new Currency('EUR')), // EUR 1,500.00
    memo:    'EU client payment'
);
```

---

## Increase and Decrease Convenience Methods

When you do not want to think about whether an account takes a debit or a credit to increase, use `increase()` and `decrease()`. These methods inspect the account's `AccountType` category and automatically apply the correct entry.

| Account Category | `increase()` posts | `decrease()` posts |
|-----------------|--------------------|--------------------|
| Asset, Expense (debit-normal) | Debit | Credit |
| Liability, Equity, Income (credit-normal) | Credit | Debit |

```php
// Increase a Cash account (Asset) -- automatically debits
$cashAccount->increaseDollars(500.00, 'Cash received from customer');

// Decrease a Cash account (Asset) -- automatically credits
$cashAccount->decreaseDollars(200.00, 'Cash paid for supplies');

// Increase a Sales Income account (Income) -- automatically credits
$salesAccount->increaseDollars(500.00, 'New sale recorded');

// Decrease a Loan account (Liability) -- automatically debits
$loanAccount->decreaseDollars(1000.00, 'Loan repayment');
```

This is useful in application code where the semantic intent ("this account went up") is clearer than the mechanical operation ("credit this liability account"). Use `debit()` and `credit()` directly when you need to be explicit, such as inside the `Transaction` service.

---

## General Journal

The `GeneralJournal` service provides a chronological view of all journal entries in the system — the book of original entry. Every posted transaction appears here.

```php
use App\Accounting\Services\GeneralJournal;
use Carbon\Carbon;

// All posted entries
$entries = GeneralJournal::entries()->get();

// Filter by date range
$entries = GeneralJournal::entries(
    from:     Carbon::parse('2024-01-01'),
    to:       Carbon::parse('2024-12-31'),
    currency: 'USD'
)->get();

// Include unposted (draft) entries
$entries = GeneralJournal::entries(
    from:           Carbon::now()->startOfMonth(),
    includeUnposted: true
)->get();

// Entries grouped by transaction_group UUID
// Each group represents a complete double-entry transaction
$groups = GeneralJournal::transactionGroups(
    from: Carbon::now()->startOfMonth(),
    to:   Carbon::now()->endOfMonth()
);

foreach ($groups as $groupUuid => $entries) {
    // $entries is a Collection of JournalEntry models
    foreach ($entries as $entry) {
        echo $entry->account->name . ': ' . ($entry->debit ?? $entry->credit);
    }
}

// Retrieve a specific transaction group by UUID
$entries = GeneralJournal::getTransactionGroup($transactionGroupId);
```

Each `JournalEntry` returned eager-loads its `account` and `account.accountType` relationships.

---

## General Ledger

The `GeneralLedger` service provides per-account views with a running balance. The running balance respects the account's normal balance direction, so it always represents the account's economic value.

```php
use App\Accounting\Services\GeneralLedger;
use Carbon\Carbon;

// Full ledger for a specific account
$entries = GeneralLedger::forAccount($cashAccount);

foreach ($entries as $entry) {
    printf(
        "%s | Debit: %s | Credit: %s | Balance: %s\n",
        $entry->post_date->format('Y-m-d'),
        number_format(($entry->debit ?? 0) / 100, 2),
        number_format(($entry->credit ?? 0) / 100, 2),
        number_format($entry->running_balance / 100, 2)
    );
}

// Ledger for a date range (opening balance is calculated from prior entries)
$entries = GeneralLedger::forAccount(
    account: $cashAccount,
    from:    Carbon::parse('2024-07-01'),
    to:      Carbon::parse('2024-09-30')
);

// Summary of all accounts under an account type
$accountSummaries = GeneralLedger::forAccountType($currentAssetsType);

foreach ($accountSummaries as $account) {
    printf(
        "%s: $%.2f (%d entries)\n",
        $account->name,
        $account->computed_balance / 100,
        $account->entry_count
    );
}

// Point-in-time balance for an account
$balance = GeneralLedger::accountBalance($cashAccount, Carbon::parse('2024-06-30'));
```

---

## Non-Posting Transactions

Non-posting transactions represent documents that have accounting significance but do not immediately create journal entries. Common uses include quotes, proposals, estimates, and purchase orders. When a non-posting transaction is approved or converted, it creates real journal entries via `convertToPosting()`.

### Creating a Non-Posting Transaction

```php
use App\Accounting\Models\NonPostingTransaction;
use App\Accounting\Models\NonPostingLineItem;
use App\Accounting\Enums\NonPostingStatus;

// Create a quote
$quote = NonPostingTransaction::create([
    'type'        => 'quote',          // String, no enum constraint -- define your own types
    'status'      => NonPostingStatus::DRAFT,
    'number'      => 'QUO-2024-001',
    'description' => 'Website redesign proposal',
    'currency'    => 'USD',
    'due_date'    => Carbon::now()->addDays(30),
    'metadata'    => ['notes' => 'Includes 3 revision rounds'],
]);

// Add line items (amount is calculated automatically from quantity * unit_price)
$quote->lineItems()->create([
    'description' => 'UI Design',
    'quantity'    => 1.0,
    'unit_price'  => 200000,  // $2,000.00 in cents
    'sort_order'  => 1,
]);

$quote->lineItems()->create([
    'description' => 'Development',
    'quantity'    => 40.0,
    'unit_price'  => 15000,   // $150.00/hr in cents
    'sort_order'  => 2,
]);

// total_amount is automatically recalculated when line items are saved
echo $quote->total_amount; // 800000 (= 200000 + 600000) cents = $8,000.00
```

### Managing Status

```php
// Advance the status through the lifecycle
$quote->update(['status' => NonPostingStatus::OPEN]);

// Check status
$quote->status === NonPostingStatus::OPEN; // true

// Check if already converted
$quote->isConverted(); // false
```

Non-posting status values: `DRAFT`, `OPEN`, `CLOSED`, `VOIDED`, `CONVERTED`.

### Converting to Posting Entries

When a quote is accepted, convert it to real journal entries by providing an account mapping. The mapping specifies how each monetary amount should be posted.

```php
// Customer accepted the quote -- create real journal entries
$groupUuid = $quote->convertToPosting([
    [
        'account' => $accountsReceivableAccount,
        'method'  => 'debit',
        'amount'  => 800000,      // in cents
        'memo'    => 'Invoice for website redesign',
    ],
    [
        'account' => $salesIncomeAccount,
        'method'  => 'credit',
        'amount'  => 800000,
        'memo'    => 'Website redesign revenue',
    ],
]);

// Status is automatically set to CONVERTED
$quote->fresh()->status === NonPostingStatus::CONVERTED; // true
$quote->fresh()->converted_to_group;                     // The transaction_group UUID

// Attempting to convert again throws NonPostingAlreadyConverted
$quote->convertToPosting([...]); // throws NonPostingAlreadyConverted
```

### Referencing Other Models

```php
$customer = Customer::find(1);

$quote->referencesObject($customer);

// Retrieve the referenced model later
$customer = $quote->getReferencedObject();
```

---

## API Reference

### Account

| Method | Description |
|--------|-------------|
| `debit($amount, $memo, $postDate, $transactionGroup)` | Record a debit entry (amount in cents) |
| `credit($amount, $memo, $postDate, $transactionGroup)` | Record a credit entry (amount in cents) |
| `debitDollars($amount, $memo, $postDate)` | Record a debit entry (amount in dollars) |
| `creditDollars($amount, $memo, $postDate)` | Record a credit entry (amount in dollars) |
| `increase($amount, $memo, $postDate, $transactionGroup)` | Increase balance, auto-selects debit or credit |
| `decrease($amount, $memo, $postDate, $transactionGroup)` | Decrease balance, auto-selects debit or credit |
| `increaseDollars($amount, $memo, $postDate)` | Increase balance using dollar amount |
| `decreaseDollars($amount, $memo, $postDate)` | Decrease balance using dollar amount |
| `getBalance()` | Returns a `Money` object from all entries |
| `getCurrentBalance()` | Returns a `Money` object as of now |
| `getBalanceOn(Carbon $date)` | Returns a `Money` object as of the given date |
| `getBalanceInDollars()` | Returns balance as a float |
| `getCurrentBalanceInDollars()` | Returns current balance as a float |
| `getDollarsDebitedToday()` | Total debits today as a float |
| `getDollarsCreditedToday()` | Total credits today as a float |
| `getDollarsDebitedOn(Carbon $date)` | Total debits on a specific date |
| `getDollarsCreditedOn(Carbon $date)` | Total credits on a specific date |

### AccountType

| Method | Description |
|--------|-------------|
| `accounts()` | HasMany relationship to `Account` |
| `parent()` | BelongsTo self for hierarchical types |
| `children()` | HasMany self for hierarchical types |
| `journalEntries()` | HasManyThrough to all entries under this type |
| `getCurrentBalance(string $currency)` | Aggregate `Money` balance across all accounts |
| `getCurrentBalanceInDollars()` | Aggregate balance as a float (USD) |
| `getTypeOptions()` | Static — returns array of category options for dropdowns |

### Transaction

| Method | Description |
|--------|-------------|
| `Transaction::newDoubleEntryTransactionGroup()` | Create a new transaction builder |
| `addTransaction($account, $method, Money $money, $memo, $referencedObject, $postdate)` | Add an entry using a `Money` object |
| `addDollarTransaction($account, $method, $value, $memo, $referencedObject, $postdate)` | Add an entry using a dollar float |
| `commit()` | Validate and persist all entries; returns transaction group UUID |
| `getTransactionsPending()` | Inspect pending entries before commit |

### GeneralJournal

| Method | Description |
|--------|-------------|
| `GeneralJournal::entries($from, $to, $currency, $includeUnposted)` | Returns an Eloquent `Builder` |
| `GeneralJournal::transactionGroups($from, $to, $currency)` | Returns a `Collection` grouped by UUID |
| `GeneralJournal::getTransactionGroup(string $uuid)` | Returns a `Collection` for one transaction group |

### GeneralLedger

| Method | Description |
|--------|-------------|
| `GeneralLedger::forAccount($account, $from, $to)` | Returns a `Collection` with `running_balance` on each entry |
| `GeneralLedger::forAccountType($accountType, $from, $to, $currency)` | Returns accounts with `computed_balance` and `entry_count` |
| `GeneralLedger::accountBalance($account, $asOf)` | Returns a `Money` object |

### HasAccount Trait

| Method | Description |
|--------|-------------|
| `account()` | MorphOne relationship to `Account` |
| `initAccount($currency, $accountTypeId)` | Create and attach an account to this model |

### AccountCategory Enum

| Method | Description |
|--------|-------------|
| `isDebitNormal()` | Returns `true` for ASSET and EXPENSE |
| `isCreditNormal()` | Returns `true` for LIABILITY, EQUITY, and INCOME |
| `balanceSign()` | Returns `1` for debit-normal, `-1` for credit-normal |
| `AccountCategory::values()` | Returns all category values as an array of strings |

---

## Exceptions

| Exception | Thrown When |
|-----------|-------------|
| `AccountAlreadyExists` | `initAccount()` is called on a model that already has an account |
| `DebitsAndCreditsDoNotEqual` | `Transaction::commit()` is called with unbalanced entries |
| `InvalidJournalEntryValue` | An entry amount of zero or less is added to a `Transaction` |
| `InvalidJournalMethod` | A method other than `'debit'` or `'credit'` is passed to `addTransaction()` |
| `TransactionCouldNotBeProcessed` | A database error occurs during `Transaction::commit()` |
| `NonPostingAlreadyConverted` | `convertToPosting()` is called on a transaction that was already converted |
| `InvalidAccountCategory` | An invalid category value is used |

All exceptions extend `App\Accounting\Exceptions\BaseException`.

---

## Testing

```bash
# Run the full test suite
make test

# Test against a specific Laravel version
./test-versions.sh 11

# Test against all supported Laravel versions (8 through 12)
./test-versions.sh
```

The `tests/` directory contains complex use-case scenarios that serve as executable documentation for real-world accounting workflows including product sales with inventory, cost of goods sold, multi-currency transactions, and period-end closing entries.

---

## License

This package is open-sourced software licensed under the [MIT license](LICENSE).
