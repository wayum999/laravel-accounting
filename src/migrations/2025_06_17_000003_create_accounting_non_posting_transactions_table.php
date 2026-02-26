<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('accounting_non_posting_transactions', function (Blueprint $table) {
            $table->char('id', 36)->primary();
            $table->string('type', 50);
            $table->string('status', 20)->default('draft');
            $table->string('number', 50)->nullable();
            $table->text('description')->nullable();
            $table->char('currency', 3);
            $table->bigInteger('total_amount')->default(0);
            $table->string('ref_class', 64)->nullable();
            $table->unsignedInteger('ref_class_id')->nullable();
            $table->string('morphed_type', 32)->nullable();
            $table->unsignedInteger('morphed_id')->nullable();
            $table->json('metadata')->nullable();
            $table->dateTime('due_date')->nullable();
            $table->char('converted_to_group', 36)->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('type', 'idx_npt_type');
            $table->index('status', 'idx_npt_status');
            $table->index('number', 'idx_npt_number');
            $table->index(['ref_class', 'ref_class_id'], 'idx_npt_ref');
            $table->index(['morphed_type', 'morphed_id'], 'idx_npt_morphed');
            $table->index('due_date', 'idx_npt_due_date');
            $table->index('converted_to_group', 'idx_npt_converted_group');
            $table->index('deleted_at', 'idx_npt_deleted_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('accounting_non_posting_transactions');
    }
};
