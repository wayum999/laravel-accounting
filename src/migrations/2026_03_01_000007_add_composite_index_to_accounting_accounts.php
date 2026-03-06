<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds a composite index on (type, is_active) to accounting_accounts.
 *
 * All five report generators filter by both columns. Without a composite index
 * the database must scan the entire accounts table and apply two separate
 * single-column index lookups per query. This index satisfies the most common
 * query pattern in a single range scan.
 *
 * Also adds a unique index on `code` to enforce uniqueness at the database level,
 * preventing the TOCTOU check-then-create race in ChartOfAccountsSeeder.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('accounting_accounts', function (Blueprint $table) {
            // Composite index for the (type, is_active) filter used by all report generators
            $table->index(['type', 'is_active'], 'accounting_accounts_type_is_active_index');

            // Unique constraint on account code (nullable; NULL values are not considered equal
            // so multiple NULL codes are permitted per SQL standard)
            $table->unique('code', 'accounting_accounts_code_unique');
        });
    }

    public function down(): void
    {
        Schema::table('accounting_accounts', function (Blueprint $table) {
            $table->dropIndex('accounting_accounts_type_is_active_index');
            $table->dropUnique('accounting_accounts_code_unique');
        });
    }
};
