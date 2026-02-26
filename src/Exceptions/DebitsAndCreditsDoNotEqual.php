<?php

declare(strict_types=1);

namespace Williamlettieri\Accounting\Exceptions;

class DebitsAndCreditsDoNotEqual extends BaseException
{
    public function __construct($message = null)
    {
        parent::__construct('Double Entry requires that debits equal credits.' . $message);
    }
}
