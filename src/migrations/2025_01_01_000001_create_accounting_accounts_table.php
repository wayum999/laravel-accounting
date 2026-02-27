<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('accounting_accounts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('parent_id')->nullable();
            $table->string('name');
            $table->string('code', 50)->nullable();
            $table->string('type', 20); // AccountType enum: asset|liability|equity|income|expense
            $table->string('sub_type', 100)->nullable();
            $table->text('description')->nullable();
            $table->char('currency', 3)->default('USD');
            $table->bigInteger('cached_balance')->default(0);
            $table->boolean('is_active')->default(true);
            $table->string('accountable_type')->nullable();
            $table->unsignedBigInteger('accountable_id')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('parent_id')
                ->references('id')
                ->on('accounting_accounts')
                ->nullOnDelete();

            $table->index(['accountable_type', 'accountable_id']);
            $table->index('type');
            $table->index('code');
            $table->index('is_active');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('accounting_accounts');
    }
};
