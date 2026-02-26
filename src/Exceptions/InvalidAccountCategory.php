<?php

declare(strict_types=1);

namespace App\Accounting\Exceptions;

class InvalidAccountCategory extends BaseException
{
    public $message = 'Invalid account category. Must be one of: asset, liability, equity, income, expense.';
}
