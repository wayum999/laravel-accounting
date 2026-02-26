<?php

declare(strict_types=1);

namespace Williamlettieri\Accounting\Exceptions;

class JournalAlreadyExists extends BaseException
{
    public $message = 'Journal already exists.';
}
