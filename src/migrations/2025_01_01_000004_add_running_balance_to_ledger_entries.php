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
            $table->bigInteger('running_balance')->default(0)->after('credit');
        });
    }

    public function down(): void
    {
        Schema::table('accounting_ledger_entries', function (Blueprint $table) {
            $table->dropColumn('running_balance');
        });
    }
};
