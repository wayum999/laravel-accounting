<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('accounting_non_posting_line_items', function (Blueprint $table) {
            $table->char('id', 36)->primary();
            $table->char('non_posting_transaction_id', 36);
            $table->unsignedInteger('account_id')->nullable();
            $table->string('description', 500)->nullable();
            $table->decimal('quantity', 10, 4)->default(1);
            $table->bigInteger('unit_price')->default(0);
            $table->bigInteger('amount')->default(0);
            $table->string('ref_class', 64)->nullable();
            $table->unsignedInteger('ref_class_id')->nullable();
            $table->integer('sort_order')->default(0);
            $table->timestamps();

            $table->index('non_posting_transaction_id', 'idx_npli_transaction');
            $table->index('account_id', 'idx_npli_account');
            $table->index(['ref_class', 'ref_class_id'], 'idx_npli_ref');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('accounting_non_posting_line_items');
    }
};
