<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE products DROP CONSTRAINT products_status_check');
        DB::table('products')->where('status', 'active')->update(['status' => 'published']);
        DB::statement("ALTER TABLE products ADD CONSTRAINT products_status_check CHECK (status IN ('draft', 'published', 'archived'))");
        Schema::table('products', function (Blueprint $table): void {
            $table->index(['status', 'id']);
        });
        Schema::create('product_categories', function (Blueprint $table): void {
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('category_id')->constrained()->cascadeOnDelete();
            $table->primary(['product_id', 'category_id']);
            $table->index(['category_id', 'product_id']);
        });
        DB::table('products')->whereNotNull('category_id')->orderBy('id')->chunkById(200, function ($products): void {
            foreach ($products as $product) {
                DB::table('product_categories')->insert(['product_id' => $product->id, 'category_id' => $product->category_id]);
            }
        });
        Schema::table('product_tags', function (Blueprint $table): void {
            $table->index(['tag_id', 'product_id']);
        });
        Schema::table('product_variants', function (Blueprint $table): void {
            $table->string('option_signature', 64)->nullable();
            $table->decimal('sale_price', 12, 2)->nullable();
            $table->timestampTz('sale_starts_at')->nullable();
            $table->timestampTz('sale_ends_at')->nullable();
            $table->decimal('cost_price', 12, 2)->nullable();
            $table->string('barcode', 100)->nullable();
            $table->unsignedInteger('weight_grams')->nullable();
            $table->integer('reserved_quantity')->default(0);
            $table->boolean('allow_backorders')->default(false);
            $table->unsignedInteger('low_stock_threshold')->default(5);
            $table->index(['product_id', 'is_active']);
        });
        DB::table('product_variants')->orderBy('id')->chunkById(200, function ($variants): void {
            foreach ($variants as $variant) {
                $options = [];
                foreach (json_decode($variant->attributes, true) ?? [] as $key => $value) {
                    $options[mb_strtolower(trim((string) $key))] = mb_strtolower(trim((string) $value));
                }
                ksort($options);
                $changes = ['option_signature' => hash('sha256', json_encode((object) $options, JSON_THROW_ON_ERROR))];
                if ($variant->compare_at_price !== null && (float) $variant->compare_at_price > (float) $variant->price) {
                    $changes += ['price' => $variant->compare_at_price, 'sale_price' => $variant->price, 'compare_at_price' => null];
                }
                DB::table('product_variants')->where('id', $variant->id)->update($changes);
            }
        });
        Schema::table('product_variants', function (Blueprint $table): void {
            $table->unique(['product_id', 'option_signature']);
        });
        Schema::create('catalog_locks', function (Blueprint $table): void {
            $table->unsignedInteger('id')->primary();
        });
        DB::table('catalog_locks')->insert(['id' => 1]);
        Schema::create('stock_reservations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('variant_id')->constrained('product_variants')->restrictOnDelete();
            $table->foreignId('order_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('quantity');
            $table->string('status', 20)->default('reserved');
            $table->timestampsTz();
            $table->unique(['order_id', 'variant_id']);
        });
        Schema::table('inventory_transactions', function (Blueprint $table): void {
            $table->foreignId('actor_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->integer('reserved_change')->default(0);
            $table->string('operation_key', 100)->nullable()->unique();
        });
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE inventory_transactions DROP CONSTRAINT inventory_transactions_type_check');
            DB::statement('ALTER TABLE inventory_transactions DROP CONSTRAINT inventory_transactions_quantity_check');
            DB::statement("ALTER TABLE inventory_transactions ADD CONSTRAINT inventory_transactions_type_check CHECK (transaction_type IN ('stock_in', 'stock_out', 'adjustment', 'return', 'reserve', 'release', 'deduct'))");
            DB::statement('ALTER TABLE inventory_transactions ADD CONSTRAINT inventory_transactions_quantity_check CHECK (quantity_change <> 0 OR reserved_change <> 0)');
            DB::statement('ALTER TABLE product_variants ADD CONSTRAINT variants_available_check CHECK (reserved_quantity >= 0 AND (allow_backorders OR stock_quantity >= reserved_quantity))');
            DB::statement('ALTER TABLE product_variants ADD CONSTRAINT variants_sale_check CHECK (sale_price IS NULL OR (sale_price >= 0 AND sale_price < price))');
        }
    }

    public function down(): void
    {
        if (DB::table('stock_reservations')->exists()) {
            throw new RuntimeException('Reservation history must be preserved. Use a forward migration instead of rolling back this catalog schema.');
        }
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE product_variants DROP CONSTRAINT variants_sale_check');
            DB::statement('ALTER TABLE product_variants DROP CONSTRAINT variants_available_check');
            DB::statement('ALTER TABLE inventory_transactions DROP CONSTRAINT inventory_transactions_quantity_check');
            DB::statement('ALTER TABLE inventory_transactions DROP CONSTRAINT inventory_transactions_type_check');
        }
        Schema::table('inventory_transactions', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('actor_id');
            $table->dropColumn(['reserved_change', 'operation_key']);
        });
        Schema::dropIfExists('stock_reservations');
        Schema::dropIfExists('catalog_locks');
        Schema::table('product_variants', function (Blueprint $table): void {
            $table->dropUnique(['product_id', 'option_signature']);
            $table->dropIndex(['product_id', 'is_active']);
            $table->dropColumn(['option_signature', 'sale_price', 'sale_starts_at', 'sale_ends_at', 'cost_price', 'barcode', 'weight_grams', 'reserved_quantity', 'allow_backorders', 'low_stock_threshold']);
        });
        Schema::table('product_tags', fn (Blueprint $table) => $table->dropIndex(['tag_id', 'product_id']));
        Schema::dropIfExists('product_categories');
        Schema::table('products', fn (Blueprint $table) => $table->dropIndex(['status', 'id']));
        DB::statement('ALTER TABLE products DROP CONSTRAINT products_status_check');
        DB::table('products')->where('status', 'published')->update(['status' => 'active']);
        DB::statement("ALTER TABLE products ADD CONSTRAINT products_status_check CHECK (status IN ('draft', 'active', 'archived'))");
        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE inventory_transactions ADD CONSTRAINT inventory_transactions_type_check CHECK (transaction_type IN ('stock_in', 'stock_out', 'adjustment', 'return')) NOT VALID");
            DB::statement('ALTER TABLE inventory_transactions ADD CONSTRAINT inventory_transactions_quantity_check CHECK (quantity_change <> 0) NOT VALID');
        }
    }
};
