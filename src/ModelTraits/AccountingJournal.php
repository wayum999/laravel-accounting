<?php

declare(strict_types=1);

namespace Williamlettieri\Accounting\ModelTraits;

use Illuminate\Database\Eloquent\Relations\MorphOne;
use Williamlettieri\Accounting\Exceptions\JournalAlreadyExists;
use Williamlettieri\Accounting\Models\Journal;

trait AccountingJournal
{
    public function journal(): MorphOne
    {
        return $this->morphOne(Journal::class, 'morphed');
    }

    /**
     * Initialize a journal for a given model object
     *
     * @param null|string $currencyCode
     * @param null|string $ledgerId
     * @return mixed
     * @throws JournalAlreadyExists
     */
    public function initJournal(?string $currencyCode = 'USD', ?string $ledgerId = null)
    {
        if (!$this->journal) {
            $journal = new Journal();
            $journal->ledger_id = $ledgerId;
            $journal->currency = $currencyCode;
            $journal->balance = 0;
            return $this->journal()->save($journal);
        }
        throw new JournalAlreadyExists;
    }
}
