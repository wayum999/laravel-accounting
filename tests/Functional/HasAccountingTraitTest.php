<?php

declare(strict_types=1);

namespace Tests\Functional;

use App\Accounting\Enums\AccountSubType;
use App\Accounting\Enums\AccountType;
use App\Accounting\Exceptions\AccountAlreadyExistsException;
use App\Accounting\Models\Account;
use App\Accounting\Traits\HasAccounting;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * A dummy model that uses the HasAccounting trait, for testing.
 */
class AccountingUser extends Model
{
    use HasAccounting;

    protected $table = 'test_users';
    protected $guarded = [];
}

class HasAccountingTraitTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Create a test users table
        Schema::create('test_users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->timestamps();
        });
    }

    #[Test]
    public function it_creates_an_account_for_a_model(): void
    {
        $user = AccountingUser::create(['name' => 'John']);

        $account = $user->createAccount('Wallet', AccountType::ASSET, '1000');

        $this->assertEquals('Wallet', $account->name);
        $this->assertEquals(AccountType::ASSET, $account->type);
        $this->assertEquals('1000', $account->code);
        $this->assertEquals(AccountingUser::class, $account->accountable_type);
        $this->assertEquals($user->id, $account->accountable_id);
    }

    #[Test]
    public function it_retrieves_accounts(): void
    {
        $user = AccountingUser::create(['name' => 'John']);

        $user->createAccount('Wallet', AccountType::ASSET);
        $user->createAccount('Credit', AccountType::LIABILITY);

        $this->assertCount(2, $user->accounts);
    }

    #[Test]
    public function it_retrieves_account_by_name(): void
    {
        $user = AccountingUser::create(['name' => 'John']);

        $user->createAccount('Wallet', AccountType::ASSET);
        $user->createAccount('Credit', AccountType::LIABILITY);

        $wallet = $user->account('Wallet');
        $this->assertEquals('Wallet', $wallet->name);
        $this->assertEquals(AccountType::ASSET, $wallet->type);
    }

    #[Test]
    public function it_returns_first_account_when_no_name_given(): void
    {
        $user = AccountingUser::create(['name' => 'John']);

        $first = $user->createAccount('Primary', AccountType::ASSET);
        $user->createAccount('Secondary', AccountType::ASSET);

        $account = $user->account();
        $this->assertEquals($first->id, $account->id);
    }

    #[Test]
    public function it_returns_null_when_no_accounts_exist(): void
    {
        $user = AccountingUser::create(['name' => 'John']);

        $this->assertNull($user->account());
        $this->assertNull($user->account('Nonexistent'));
    }

    #[Test]
    public function it_throws_on_duplicate_account_name(): void
    {
        $user = AccountingUser::create(['name' => 'John']);

        $user->createAccount('Wallet', AccountType::ASSET);

        $this->expectException(AccountAlreadyExistsException::class);
        $user->createAccount('Wallet', AccountType::ASSET);
    }

    #[Test]
    public function different_models_can_have_same_account_name(): void
    {
        $user1 = AccountingUser::create(['name' => 'John']);
        $user2 = AccountingUser::create(['name' => 'Jane']);

        $account1 = $user1->createAccount('Wallet', AccountType::ASSET);
        $account2 = $user2->createAccount('Wallet', AccountType::ASSET);

        $this->assertNotEquals($account1->id, $account2->id);
    }

    #[Test]
    public function it_creates_account_with_custom_currency(): void
    {
        $user = AccountingUser::create(['name' => 'John']);

        $account = $user->createAccount('Euro Wallet', AccountType::ASSET, null, 'EUR');

        $this->assertEquals('EUR', $account->currency);
    }

    #[Test]
    public function it_creates_account_with_sub_type(): void
    {
        $user = AccountingUser::create(['name' => 'John']);

        $account = $user->createAccount('Bank', AccountType::ASSET, '1010', 'USD', AccountSubType::BANK);

        $this->assertEquals(AccountSubType::BANK, $account->sub_type);
    }
}
