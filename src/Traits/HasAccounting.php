<?php

declare(strict_types=1);

namespace App\Accounting\Traits;

use App\Accounting\Enums\AccountSubType;
use App\Accounting\Enums\AccountType;
use App\Accounting\Exceptions\AccountAlreadyExistsException;
use App\Accounting\Models\Account;
use Illuminate\Database\Eloquent\Relations\MorphMany;

trait HasAccounting
{
    /**
     * Get all accounting accounts owned by this model.
     */
    public function accounts(): MorphMany
    {
        return $this->morphMany(Account::class, 'accountable');
    }

    /**
     * Get a specific account by name, or the first account if no name given.
     */
    public function account(?string $name = null): ?Account
    {
        if ($name !== null) {
            return $this->accounts()->where('name', $name)->first();
        }

        return $this->accounts()->first();
    }

    /**
     * Create a new accounting account for this model.
     *
     * @throws AccountAlreadyExistsException if an account with the same name already exists on this model
     */
    public function createAccount(
        string $name,
        AccountType $type,
        ?string $code = null,
        string $currency = 'USD',
        ?AccountSubType $subType = null,
    ): Account {
        // Check for duplicate name on this model
        if ($this->accounts()->where('name', $name)->exists()) {
            throw new AccountAlreadyExistsException(
                "An account named '{$name}' already exists for this model."
            );
        }

        return $this->accounts()->create([
            'name' => $name,
            'type' => $type,
            'code' => $code,
            'currency' => $currency,
            'sub_type' => $subType,
        ]);
    }
}
