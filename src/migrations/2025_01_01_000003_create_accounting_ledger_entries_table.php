<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('accounting_ledger_entries', function (Blueprint $table) {
            $table->id();
            $table->uuid('journal_entry_id')->nullable();
            $table->unsignedBigInteger('account_id');
            $table->bigInteger('debit')->default(0);
            $table->bigInteger('credit')->default(0);
            $table->char('currency', 3)->default('USD');
            $table->string('memo', 500)->nullable();
            $table->dateTime('post_date');
            $table->json('tags')->nullable();
            $table->string('ledgerable_type')->nullable();
            $table->unsignedBigInteger('ledgerable_id')->nullable();
            $table->timestamps();

            $table->foreign('journal_entry_id')
                ->references('id')
                ->on('accounting_journal_entries')
                ->nullOnDelete();

            $table->foreign('account_id')
                ->references('id')
                ->on('accounting_accounts')
                ->cascadeOnDelete();

            $table->index(['ledgerable_type', 'ledgerable_id']);
            $table->index('account_id');
            $table->index('journal_entry_id');
            $table->index('post_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('accounting_ledger_entries');
    }
};
