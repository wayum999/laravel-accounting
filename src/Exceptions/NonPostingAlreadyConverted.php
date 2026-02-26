<?php

declare(strict_types=1);

namespace App\Accounting\Exceptions;

class NonPostingAlreadyConverted extends BaseException
{
    public $message = 'Non-posting transaction has already been converted to a posting transaction.';
}
