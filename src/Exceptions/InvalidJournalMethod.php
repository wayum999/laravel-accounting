<?php

declare(strict_types=1);

namespace Williamlettieri\Accounting\Exceptions;

class InvalidJournalMethod extends BaseException
{
    public $message = 'Journal methods must be credit or debit';
}
