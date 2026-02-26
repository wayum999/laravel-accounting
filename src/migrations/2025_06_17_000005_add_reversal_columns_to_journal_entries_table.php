<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('accounting_journal_entries', function (Blueprint $table) {
            $table->char('reversed_by', 36)->nullable()->after('is_posted');
            $table->char('reversal_of', 36)->nullable()->after('reversed_by');
            $table->boolean('is_reversed')->default(false)->after('reversal_of');

            $table->index('reversed_by', 'idx_entries_reversed_by');
            $table->index('reversal_of', 'idx_entries_reversal_of');
            $table->index('is_reversed', 'idx_entries_is_reversed');
        });
    }

    public function down(): void
    {
        Schema::table('accounting_journal_entries', function (Blueprint $table) {
            $table->dropIndex('idx_entries_reversed_by');
            $table->dropIndex('idx_entries_reversal_of');
            $table->dropIndex('idx_entries_is_reversed');
            $table->dropColumn(['reversed_by', 'reversal_of', 'is_reversed']);
        });
    }
};
