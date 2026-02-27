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
            $table->dropForeign(['account_id']);
            $table->foreign('account_id')
                ->references('id')
                ->on('accounting_accounts')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('accounting_ledger_entries', function (Blueprint $table) {
            $table->dropForeign(['account_id']);
            $table->foreign('account_id')
                ->references('id')
                ->on('accounting_accounts')
                ->cascadeOnDelete();
        });
    }
};
