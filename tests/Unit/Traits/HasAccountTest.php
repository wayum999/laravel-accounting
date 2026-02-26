<?php

declare(strict_types=1);

namespace Tests\Unit\Traits;

use Tests\Unit\TestCase;
use App\Accounting\ModelTraits\HasAccount;
use App\Accounting\Models\Account;
use App\Accounting\Models\AccountType;
use App\Accounting\Enums\AccountCategory;
use App\Accounting\Exceptions\AccountAlreadyExists;
use Illuminate\Database\Eloquent\Model;

class HasAccountTest extends TestCase
{
    public function test_account_relationship(): void
    {
        $model = new TestableModel();

        $relationship = $model->account();

        $this->assertInstanceOf(\Illuminate\Database\Eloquent\Relations\MorphOne::class, $relationship);
    }

    public function test_init_account_creates_new_account(): void
    {
        $model = new TestableModel();
        $model->id = 1;
        $model->save();

        $accountType = AccountType::create([
            'name' => 'Test Asset',
            'type' => AccountCategory::ASSET->value,
        ]);

        $account = $model->initAccount('USD', $accountType->id);

        $this->assertInstanceOf(Account::class, $account);
        $this->assertEquals('USD', $account->currency);
        $this->assertTrue($account->accountType->is($accountType));
        $this->assertEquals(TestableModel::class, $account->morphed_type);
        $this->assertEquals($model->id, $account->morphed_id);
    }

    public function test_init_account_throws_exception_when_account_already_exists(): void
    {
        $this->expectException(AccountAlreadyExists::class);

        $model = new TestableModel();
        $model->id = 2;
        $model->save();

        $accountType = AccountType::create([
            'name' => 'Test Asset',
            'type' => AccountCategory::ASSET->value,
        ]);

        // Create account first time
        $model->initAccount('USD', $accountType->id);

        // Load the relationship to trigger the !$this->account check
        $model->load('account');

        // Try to create again - should throw exception
        $model->initAccount('USD', $accountType->id);
    }

    public function test_init_account_with_minimal_parameters(): void
    {
        $model = new TestableModel();
        $model->id = 3;
        $model->save();

        $account = $model->initAccount('EUR');

        $this->assertInstanceOf(Account::class, $account);
        $this->assertEquals('EUR', $account->currency);
        $this->assertNull($account->account_type_id);
        $this->assertEquals(TestableModel::class, $account->morphed_type);
        $this->assertEquals($model->id, $account->morphed_id);
    }
}

/**
 * Test model that uses the HasAccount trait
 */
class TestableModel extends Model
{
    use HasAccount;

    protected $table = 'test_models';
    protected $fillable = ['name'];

    // Override the table creation for testing
    public static function boot()
    {
        parent::boot();

        // Create the test table if it doesn't exist
        if (!app('db')->getSchemaBuilder()->hasTable('test_models')) {
            app('db')->getSchemaBuilder()->create('test_models', function ($table) {
                $table->id();
                $table->string('name')->nullable();
                $table->timestamps();
            });
        }
    }
}
