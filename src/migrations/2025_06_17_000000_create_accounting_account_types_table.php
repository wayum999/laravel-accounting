<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('accounting_account_types', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name');
            $table->enum('type', ['asset', 'liability', 'equity', 'income', 'expense']);
            $table->string('code', 20)->nullable();
            $table->unsignedInteger('parent_id')->nullable();
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index('type', 'idx_account_types_type');
            $table->index('name', 'idx_account_types_name');
            $table->index('code', 'idx_account_types_code');
            $table->index('parent_id', 'idx_account_types_parent');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('accounting_account_types');
    }
};
