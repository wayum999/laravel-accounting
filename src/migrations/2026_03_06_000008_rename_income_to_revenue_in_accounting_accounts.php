<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Rename the 'income' account type value to 'revenue' to align with standard
 * accounting terminology. AccountType::REVENUE (was INCOME) represents the
 * primary operating revenue type (credit-normal).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('accounting_accounts')
            ->where('type', 'income')
            ->update(['type' => 'revenue']);
    }

    public function down(): void
    {
        DB::table('accounting_accounts')
            ->where('type', 'revenue')
            ->update(['type' => 'income']);
    }
};
