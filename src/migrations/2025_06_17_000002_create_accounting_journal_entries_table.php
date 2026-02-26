<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('accounting_journal_entries', function (Blueprint $table) {
            $table->char('id', 36)->primary();
            $table->char('transaction_group', 36)->nullable();
            $table->unsignedInteger('account_id');
            $table->bigInteger('debit')->nullable()->default(0);
            $table->bigInteger('credit')->nullable()->default(0);
            $table->char('currency', 3);
            $table->string('memo', 500)->nullable();
            $table->json('tags')->nullable();
            $table->string('ref_class', 64)->nullable();
            $table->unsignedInteger('ref_class_id')->nullable();
            $table->boolean('is_posted')->default(true);
            $table->timestamps();
            $table->dateTime('post_date')->index();
            $table->softDeletes();

            $table->index('account_id', 'idx_entries_account_id');
            $table->index('transaction_group', 'idx_entries_group');
            $table->index('currency', 'idx_entries_currency');
            $table->index(['ref_class', 'ref_class_id'], 'idx_entries_ref');
            $table->index(['account_id', 'post_date'], 'idx_entries_account_date');
            $table->index(['post_date', 'account_id'], 'idx_entries_date_account');
            $table->index('deleted_at', 'idx_entries_deleted_at');
            $table->index(['account_id', 'currency', 'post_date'], 'idx_entries_account_currency_date');
            $table->index(['transaction_group', 'post_date'], 'idx_entries_group_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('accounting_journal_entries');
    }
};
