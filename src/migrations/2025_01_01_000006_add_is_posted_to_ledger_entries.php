<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('accounting_ledger_entries', function (Blueprint $table) {
            $table->boolean('is_posted')->default(true)->after('running_balance');
            $table->index(['account_id', 'is_posted', 'post_date']);
        });
    }

    public function down(): void
    {
        Schema::table('accounting_ledger_entries', function (Blueprint $table) {
            $table->dropIndex(['account_id', 'is_posted', 'post_date']);
            $table->dropColumn('is_posted');
        });
    }
};
