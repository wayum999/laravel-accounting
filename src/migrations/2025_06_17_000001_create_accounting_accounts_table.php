<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('accounting_accounts', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('account_type_id')->nullable();
            $table->string('number', 20)->nullable();
            $table->string('name')->nullable();
            $table->bigInteger('balance')->default(0);
            $table->char('currency', 3);
            $table->string('morphed_type', 32);
            $table->unsignedInteger('morphed_id');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index('account_type_id', 'idx_accounts_account_type_id');
            $table->index('currency', 'idx_accounts_currency');
            $table->index(['morphed_type', 'morphed_id'], 'idx_accounts_morphed');
            $table->index('balance', 'idx_accounts_balance');
            $table->index('number', 'idx_accounts_number');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('accounting_accounts');
    }
};
