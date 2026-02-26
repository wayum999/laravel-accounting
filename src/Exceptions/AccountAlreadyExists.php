<?php

declare(strict_types=1);

namespace App\Accounting\Exceptions;

class AccountAlreadyExists extends BaseException
{
    public $message = 'Account already exists.';
}
