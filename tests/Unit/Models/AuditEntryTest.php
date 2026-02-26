<?php

declare(strict_types=1);

namespace Tests\Unit\Models;

use Tests\Unit\TestCase;
use App\Accounting\Models\Account;
use App\Accounting\Models\AccountType;
use App\Accounting\Models\AuditEntry;
use App\Accounting\Enums\AccountCategory;
use Carbon\Carbon;

class AuditEntryTest extends TestCase
{
    private AccountType $assetType;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app['config']->set('accounting.audit.enabled', true);
        $this->app['config']->set('accounting.audit.exclude_fields', ['updated_at', 'created_at', 'deleted_at']);

        $this->assetType = AccountType::create([
            'name' => 'Assets',
            'type' => AccountCategory::ASSET,
            'code' => 'ASSET',
        ]);
    }

    public function test_record_creates_audit_entry(): void
    {
        $account = Account::create([
            'account_type_id' => $this->assetType->id,
            'name' => 'Cash',
            'number' => '1000',
            'currency' => 'USD',
            'morphed_type' => 'system',
            'morphed_id' => 0,
        ]);

        $audit = AuditEntry::record($account, 'created', null, ['name' => 'Cash']);

        $this->assertDatabaseHas('accounting_audit_entries', [
            'auditable_type' => Account::class,
            'event' => 'created',
        ]);
    }

    public function test_for_model_returns_entries(): void
    {
        $account = Account::create([
            'account_type_id' => $this->assetType->id,
            'name' => 'Cash',
            'number' => '1000',
            'currency' => 'USD',
            'morphed_type' => 'system',
            'morphed_id' => 0,
        ]);

        AuditEntry::record($account, 'created', null, ['name' => 'Cash']);
        AuditEntry::record($account, 'updated', ['name' => 'Cash'], ['name' => 'Cash Account']);

        $entries = AuditEntry::forModel($account)->get();
        $this->assertCount(2, $entries);
    }

    public function test_for_model_type_returns_entries(): void
    {
        $account1 = Account::create([
            'account_type_id' => $this->assetType->id,
            'name' => 'Cash',
            'number' => '1000',
            'currency' => 'USD',
            'morphed_type' => 'system',
            'morphed_id' => 0,
        ]);

        $account2 = Account::create([
            'account_type_id' => $this->assetType->id,
            'name' => 'Bank',
            'number' => '1010',
            'currency' => 'USD',
            'morphed_type' => 'system',
            'morphed_id' => 0,
        ]);

        AuditEntry::record($account1, 'created', null, ['name' => 'Cash']);
        AuditEntry::record($account2, 'created', null, ['name' => 'Bank']);

        $entries = AuditEntry::forModelType(Account::class)->get();
        $this->assertCount(2, $entries);
    }

    public function test_between_filters_by_date(): void
    {
        $account = Account::create([
            'account_type_id' => $this->assetType->id,
            'name' => 'Cash',
            'number' => '1000',
            'currency' => 'USD',
            'morphed_type' => 'system',
            'morphed_id' => 0,
        ]);

        AuditEntry::record($account, 'created', null, ['name' => 'Cash']);

        $entries = AuditEntry::between(
            Carbon::now()->subDay(),
            Carbon::now()->addDay()
        )->get();

        $this->assertGreaterThanOrEqual(1, $entries->count());
    }

    public function test_audit_entry_is_immutable(): void
    {
        $account = Account::create([
            'account_type_id' => $this->assetType->id,
            'name' => 'Cash',
            'number' => '1000',
            'currency' => 'USD',
            'morphed_type' => 'system',
            'morphed_id' => 0,
        ]);

        $audit = AuditEntry::record($account, 'created', null, ['name' => 'Cash']);

        // Attempt to update should fail silently (returns false)
        $result = $audit->update(['event' => 'modified']);
        $audit->refresh();
        $this->assertEquals('created', $audit->event);
    }

    public function test_audit_entry_cannot_be_deleted(): void
    {
        $account = Account::create([
            'account_type_id' => $this->assetType->id,
            'name' => 'Cash',
            'number' => '1000',
            'currency' => 'USD',
            'morphed_type' => 'system',
            'morphed_id' => 0,
        ]);

        $audit = AuditEntry::record($account, 'created', null, ['name' => 'Cash']);
        $auditId = $audit->id;

        $audit->delete();

        $this->assertDatabaseHas('accounting_audit_entries', ['id' => $auditId]);
    }

    public function test_audit_stores_old_and_new_values(): void
    {
        $account = Account::create([
            'account_type_id' => $this->assetType->id,
            'name' => 'Cash',
            'number' => '1000',
            'currency' => 'USD',
            'morphed_type' => 'system',
            'morphed_id' => 0,
        ]);

        $audit = AuditEntry::record(
            $account,
            'updated',
            ['name' => 'Cash'],
            ['name' => 'Cash Account']
        );

        $this->assertEquals(['name' => 'Cash'], $audit->old_values);
        $this->assertEquals(['name' => 'Cash Account'], $audit->new_values);
    }

    public function test_audit_entry_structure(): void
    {
        $account = Account::create([
            'account_type_id' => $this->assetType->id,
            'name' => 'Cash',
            'number' => '1000',
            'currency' => 'USD',
            'morphed_type' => 'system',
            'morphed_id' => 0,
        ]);

        $audit = AuditEntry::record(
            $account,
            'created',
            null,
            ['name' => 'Cash'],
            ['context' => 'test']
        );

        $this->assertEquals(Account::class, $audit->auditable_type);
        $this->assertEquals($account->id, $audit->auditable_id);
        $this->assertEquals('created', $audit->event);
        $this->assertNull($audit->user_id);
        $this->assertEquals(['context' => 'test'], $audit->metadata);
    }
}
