<?php

declare(strict_types=1);

namespace App\Accounting;

use Carbon\Carbon;
use App\Accounting\Models\Account;
use Money\Money;
use Money\Currency;
use App\Accounting\Exceptions\{
    InvalidJournalEntryValue,
    InvalidJournalMethod,
    DebitsAndCreditsDoNotEqual,
    TransactionCouldNotBeProcessed,
    TransactionAlreadyReversedException
};
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class Transaction
{
    protected array $transactionsPending = [];

    public static function newDoubleEntryTransactionGroup(): self
    {
        return new self;
    }

    public function addTransaction(
        Account $account,
        string $method,
        Money $money,
        ?string $memo = null,
        mixed $referencedObject = null,
        ?Carbon $postdate = null
    ): void {
        if (!in_array($method, ['credit', 'debit'], true)) {
            throw new InvalidJournalMethod;
        }

        if ($money->getAmount() <= 0) {
            throw new InvalidJournalEntryValue();
        }

        $this->transactionsPending[] = [
            'account' => $account,
            'method' => $method,
            'money' => $money,
            'memo' => $memo,
            'referencedObject' => $referencedObject,
            'postdate' => $postdate
        ];
    }

    public function addDollarTransaction(
        Account $account,
        string $method,
        float|int|string $value,
        ?string $memo = null,
        mixed $referencedObject = null,
        ?Carbon $postdate = null
    ): void {
        $value = (int) ($value * 100);
        $money = new Money($value, new Currency('USD'));
        $this->addTransaction($account, $method, $money, $memo, $referencedObject, $postdate);
    }

    public function getTransactionsPending(): array
    {
        return $this->transactionsPending;
    }

    public function commit(): string
    {
        $this->verifyTransactionCreditsEqualDebits();

        try {
            $transactionGroupUUID = Str::uuid()->toString();
            DB::beginTransaction();

            foreach ($this->transactionsPending as $transactionPending) {
                $entry = $transactionPending['account']->{$transactionPending['method']}(
                    $transactionPending['money'],
                    $transactionPending['memo'],
                    $transactionPending['postdate'],
                    $transactionGroupUUID
                );

                if ($object = $transactionPending['referencedObject']) {
                    $entry->referencesObject($object);
                }
            }

            DB::commit();
            return $transactionGroupUUID;

        } catch (\Exception $e) {
            DB::rollBack();
            throw new TransactionCouldNotBeProcessed(
                'Rolling Back Database. Message: ' . $e->getMessage()
            );
        }
    }

    private function verifyTransactionCreditsEqualDebits(): void
    {
        $credits = 0;
        $debits = 0;

        foreach ($this->transactionsPending as $transactionPending) {
            if ($transactionPending['method'] === 'credit') {
                $credits += $transactionPending['money']->getAmount();
            } else {
                $debits += $transactionPending['money']->getAmount();
            }
        }

        if ($credits !== $debits) {
            throw new DebitsAndCreditsDoNotEqual(
                'In this transaction, credits == ' . $credits . ' and debits == ' . $debits
            );
        }
    }

    /**
     * Reverse all entries in a transaction group.
     */
    public static function reverseGroup(
        string $transactionGroupUuid,
        ?string $memo = null,
        ?Carbon $postDate = null
    ): string {
        $entries = \App\Accounting\Models\JournalEntry::where('transaction_group', $transactionGroupUuid)
            ->whereNull('reversal_of')
            ->get();

        if ($entries->isEmpty()) {
            throw new TransactionCouldNotBeProcessed(
                "No entries found for transaction group {$transactionGroupUuid}"
            );
        }

        if ($entries->every(fn($e) => $e->is_reversed)) {
            throw TransactionAlreadyReversedException::forTransactionGroup($transactionGroupUuid);
        }

        $newGroupUuid = Str::uuid()->toString();

        DB::beginTransaction();
        try {
            foreach ($entries as $entry) {
                $reversalEntry = $entry->account->journalEntries()->create([
                    'debit' => $entry->credit,
                    'credit' => $entry->debit,
                    'currency' => $entry->currency,
                    'memo' => $memo ?? "REVERSAL: {$entry->memo}",
                    'post_date' => $postDate ?? Carbon::now(),
                    'transaction_group' => $newGroupUuid,
                    'ref_class' => $entry->ref_class,
                    'ref_class_id' => $entry->ref_class_id,
                    'is_posted' => true,
                    'reversal_of' => $entry->id,
                ]);

                $entry->update([
                    'is_reversed' => true,
                    'reversed_by' => $reversalEntry->id,
                ]);
            }

            // Reset balances for all affected accounts
            $entries->pluck('account')->unique('id')->each(function ($account) {
                $account->resetCurrentBalances();
            });

            DB::commit();
            return $newGroupUuid;
        } catch (\Exception $e) {
            DB::rollBack();
            throw new TransactionCouldNotBeProcessed(
                'Reversal failed: ' . $e->getMessage()
            );
        }
    }

    /**
     * Void all entries in a transaction group (reverse with original post dates).
     */
    public static function voidGroup(string $transactionGroupUuid): string
    {
        $entries = \App\Accounting\Models\JournalEntry::where('transaction_group', $transactionGroupUuid)
            ->whereNull('reversal_of')
            ->get();

        $postDate = $entries->first()?->post_date;

        return static::reverseGroup(
            $transactionGroupUuid,
            null,
            $postDate
        );
    }
}
