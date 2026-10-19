<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('categories', fn (Blueprint $table) => $table->index(['parent_id', 'sort_order']));
        Schema::table('products', fn (Blueprint $table) => $table->index('category_id'));
        Schema::table('product_images', fn (Blueprint $table) => $table->index(['product_id', 'sort_order', 'id']));
        Schema::table('inventory_transactions', fn (Blueprint $table) => $table->index(['variant_id', 'id']));
    }

    public function down(): void
    {
        Schema::table('inventory_transactions', fn (Blueprint $table) => $table->dropIndex(['variant_id', 'id']));
        Schema::table('product_images', fn (Blueprint $table) => $table->dropIndex(['product_id', 'sort_order', 'id']));
        Schema::table('products', fn (Blueprint $table) => $table->dropIndex(['category_id']));
        Schema::table('categories', fn (Blueprint $table) => $table->dropIndex(['parent_id', 'sort_order']));
    }
};
