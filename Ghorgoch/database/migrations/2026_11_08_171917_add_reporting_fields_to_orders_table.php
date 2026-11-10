<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->decimal('product_cost', 12, 2)->nullable();
            $table->decimal('expense_amount', 12, 2)->default(0);
            $table->timestampTz('returned_at')->nullable();
            $table->boolean('return_restocked')->default(false);
            $table->index(['created_at', 'id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->dropIndex(['created_at', 'id']);
            $table->dropColumn(['product_cost', 'expense_amount', 'returned_at', 'return_restocked']);
        });
    }
};
