<?php

declare(strict_types=1);

namespace App\Accounting\Services;

use App\Accounting\Exceptions\InvalidAmountException;
use App\Accounting\Exceptions\InvalidEntryMethodException;
use App\Accounting\Exceptions\UnbalancedTransactionException;
use App\Accounting\Models\Account;
use App\Accounting\Models\JournalEntry;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Money\Currency;
use Money\Money;

class TransactionBuilder
{
    private array $entries = [];
    private ?string $memo = null;
    private ?string $referenceNumber = null;
    private ?Carbon $date = null;
    private bool $isDraft = false;

    /**
     * Create a new TransactionBuilder instance.
     */
    public static function create(): self
    {
        return new self();
    }

    public function date(Carbon|string $date): self
    {
        $this->date = $date instanceof Carbon ? $date : Carbon::parse($date);
        return $this;
    }

    public function memo(string $memo): self
    {
        $this->memo = $memo;
        return $this;
    }

    public function reference(string $ref): self
    {
        $this->referenceNumber = $ref;
        return $this;
    }

    /**
     * Mark this transaction as a draft (unposted).
     * Draft entries do not affect account balances or reports.
     */
    public function draft(): self
    {
        $this->isDraft = true;
        return $this;
    }

    /**
     * Add a debit entry to the pending transaction.
     *
     * @throws InvalidAmountException
     */
    public function debit(
        Account $account,
        int|Money $amount,
        ?string $memo = null,
        ?Model $reference = null,
    ): self {
        return $this->addEntry($account, 'debit', $amount, $memo, $reference);
    }

    /**
     * Add a credit entry to the pending transaction.
     *
     * @throws InvalidAmountException
     */
    public function credit(
        Account $account,
        int|Money $amount,
        ?string $memo = null,
        ?Model $reference = null,
    ): self {
        return $this->addEntry($account, 'credit', $amount, $memo, $reference);
    }

    public function debitDollars(
        Account $account,
        float $dollars,
        ?string $memo = null,
        ?Model $reference = null,
    ): self {
        return $this->debit($account, (int) round($dollars * 100), $memo, $reference);
    }

    public function creditDollars(
        Account $account,
        float $dollars,
        ?string $memo = null,
        ?Model $reference = null,
    ): self {
        return $this->credit($account, (int) round($dollars * 100), $memo, $reference);
    }

    /**
     * Increase an account (auto-selects debit or credit based on account type).
     *
     * @throws InvalidAmountException
     */
    public function increase(
        Account $account,
        int|Money $amount,
        ?string $memo = null,
        ?Model $reference = null,
    ): self {
        return $account->isDebitNormal()
            ? $this->debit($account, $amount, $memo, $reference)
            : $this->credit($account, $amount, $memo, $reference);
    }

    /**
     * Decrease an account (auto-selects debit or credit based on account type).
     *
     * @throws InvalidAmountException
     */
    public function decrease(
        Account $account,
        int|Money $amount,
        ?string $memo = null,
        ?Model $reference = null,
    ): self {
        return $account->isDebitNormal()
            ? $this->credit($account, $amount, $memo, $reference)
            : $this->debit($account, $amount, $memo, $reference);
    }

    /**
     * Get the pending entries (for inspection before committing).
     */
    public function getPendingEntries(): array
    {
        return $this->entries;
    }

    /**
     * Commit the transaction. Validates balance, creates JournalEntry + LedgerEntries.
     *
     * @throws UnbalancedTransactionException
     */
    public function commit(): JournalEntry
    {
        $totalDebits = 0;
        $totalCredits = 0;

        foreach ($this->entries as $entry) {
            $totalDebits += $entry['debit'];
            $totalCredits += $entry['credit'];
        }

        if ($totalDebits !== $totalCredits) {
            throw new UnbalancedTransactionException($totalDebits, $totalCredits);
        }

        $isPosted = !$this->isDraft;

        return DB::transaction(function () use ($isPosted) {
            $journalEntry = JournalEntry::create([
                'date' => ($this->date ?? now())->toDateString(),
                'reference_number' => $this->referenceNumber,
                'memo' => $this->memo,
                'is_posted' => $isPosted,
            ]);

            $affectedAccounts = [];

            foreach ($this->entries as $entry) {
                $journalEntry->ledgerEntries()->create([
                    'account_id' => $entry['account']->id,
                    'debit' => $entry['debit'],
                    'credit' => $entry['credit'],
                    'currency' => $entry['currency'],
                    'memo' => $entry['memo'],
                    'post_date' => ($this->date ?? now()),
                    'is_posted' => $isPosted,
                    'ledgerable_type' => $entry['reference'] ? get_class($entry['reference']) : null,
                    'ledgerable_id' => $entry['reference']?->getKey(),
                ]);

                $affectedAccounts[$entry['account']->id] = $entry['account'];
            }

            // Recalculate all affected account balances (only needed for posted)
            if ($isPosted) {
                foreach ($affectedAccounts as $account) {
                    $account->recalculateBalance();
                }
            }

            return $journalEntry;
        });
    }

    // -------------------------------------------------------
    // Private
    // -------------------------------------------------------

    /**
     * @throws InvalidAmountException
     * @throws InvalidEntryMethodException
     */
    private function addEntry(
        Account $account,
        string $method,
        int|Money $amount,
        ?string $memo,
        ?Model $reference,
    ): self {
        if (!in_array($method, ['debit', 'credit'], true)) {
            throw new InvalidEntryMethodException($method);
        }

        $cents = $amount instanceof Money ? (int) $amount->getAmount() : $amount;

        if ($cents <= 0) {
            throw new InvalidAmountException();
        }

        $currency = $amount instanceof Money
            ? $amount->getCurrency()->getCode()
            : ($account->currency ?? 'USD');

        $this->entries[] = [
            'account' => $account,
            'debit' => $method === 'debit' ? $cents : 0,
            'credit' => $method === 'credit' ? $cents : 0,
            'currency' => $currency,
            'memo' => $memo ?? $this->memo,
            'reference' => $reference,
        ];

        return $this;
    }
}
