<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('accounting_fiscal_periods', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->date('start_date');
            $table->date('end_date');
            $table->string('status', 20)->default('open');
            $table->dateTime('closed_at')->nullable();
            $table->string('closed_by')->nullable();
            $table->char('closing_transaction_group', 36)->nullable();
            $table->timestamps();

            $table->index('status', 'idx_fiscal_status');
            $table->index(['start_date', 'end_date'], 'idx_fiscal_dates');
            $table->unique(['start_date', 'end_date'], 'uq_fiscal_period_dates');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('accounting_fiscal_periods');
    }
};
