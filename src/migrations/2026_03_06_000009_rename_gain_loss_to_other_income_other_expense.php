<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Rename the 'gain' and 'loss' account type values to 'other_income' and
 * 'other_expense' to align with QuickBooks naming conventions where non-operating
 * items (gains/losses on asset sales, etc.) are treated as distinct "below the line"
 * account types shown after operating income.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('accounting_accounts')
            ->where('type', 'gain')
            ->update(['type' => 'other_income']);

        DB::table('accounting_accounts')
            ->where('type', 'loss')
            ->update(['type' => 'other_expense']);
    }

    public function down(): void
    {
        DB::table('accounting_accounts')
            ->where('type', 'other_income')
            ->update(['type' => 'gain']);

        DB::table('accounting_accounts')
            ->where('type', 'other_expense')
            ->update(['type' => 'loss']);
    }
};
