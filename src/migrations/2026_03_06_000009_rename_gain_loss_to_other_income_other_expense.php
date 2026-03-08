<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Rename the 'gain' and 'loss' account type values to 'other_income' and
 * 'other_expense' to align with QuickBooks naming conventions where non-operating
 * items (gains/losses on asset sales, etc.) are treated as distinct "below the line"
 * account types shown after operating income.
 *
 * Also reclassifies existing accounts with sub_type=other_expense from
 * type=expense to type=other_expense so IncomeStatement queries them
 * correctly as non-operating expenses.
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

        // Reclassify accounts whose sub_type is other_expense but whose
        // type still points to the operating 'expense' bucket.
        DB::table('accounting_accounts')
            ->where('type', 'expense')
            ->where('sub_type', 'other_expense')
            ->update(['type' => 'other_expense']);
    }

    public function down(): void
    {
        // Revert other_expense sub-typed accounts back to operating expense
        DB::table('accounting_accounts')
            ->where('type', 'other_expense')
            ->where('sub_type', 'other_expense')
            ->update(['type' => 'expense']);

        DB::table('accounting_accounts')
            ->where('type', 'other_income')
            ->update(['type' => 'gain']);

        DB::table('accounting_accounts')
            ->where('type', 'other_expense')
            ->update(['type' => 'loss']);
    }
};
