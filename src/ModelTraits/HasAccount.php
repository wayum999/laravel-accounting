<?php

declare(strict_types=1);

namespace App\Accounting\ModelTraits;

use Illuminate\Database\Eloquent\Relations\MorphOne;
use App\Accounting\Exceptions\AccountAlreadyExists;
use App\Accounting\Models\Account;

trait HasAccount
{
    public function account(): MorphOne
    {
        return $this->morphOne(Account::class, 'morphed');
    }

    /**
     * Initialize an accounting account for this model.
     *
     * @param string|null $currencyCode ISO 4217 currency code (default: USD)
     * @param int|null $accountTypeId The AccountType to associate with
     * @return Account
     * @throws AccountAlreadyExists
     */
    public function initAccount(?string $currencyCode = 'USD', ?int $accountTypeId = null): Account
    {
        if (!$this->account) {
            $account = new Account();
            $account->account_type_id = $accountTypeId;
            $account->currency = $currencyCode;
            $account->balance = 0;
            return $this->account()->save($account);
        }
        throw new AccountAlreadyExists;
    }
}
