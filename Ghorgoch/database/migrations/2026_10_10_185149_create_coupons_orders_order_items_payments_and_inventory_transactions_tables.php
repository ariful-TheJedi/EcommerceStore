<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('coupons', function (Blueprint $table) {
            $table->id()->generatedAs()->always();
            $table->string('code', 50)->unique();
            $table->string('discount_type', 20);
            $table->decimal('discount_value', 12, 2);
            $table->decimal('max_discount', 12, 2)->nullable();
            $table->decimal('min_order_amount', 12, 2)->default(0);
            $table->integer('usage_limit')->nullable();
            $table->integer('usage_count')->default(0);
            $table->timestampTz('starts_at')->nullable();
            $table->timestampTz('expires_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->useCurrent();
        });

        Schema::create('orders', function (Blueprint $table) {
            $table->id()->generatedAs()->always();
            $table->string('order_number', 40)->unique();
            $table->foreignId('customer_id')->constrained()->restrictOnDelete();
            $table->foreignId('coupon_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status', 20)->default('pending');
            $table->string('payment_status', 20)->default('unpaid');
            $table->jsonb('shipping_address');
            $table->jsonb('billing_address')->nullable();
            $table->decimal('subtotal', 12, 2);
            $table->decimal('discount_amount', 12, 2)->default(0);
            $table->decimal('shipping_fee', 12, 2)->default(0);
            $table->decimal('tax_amount', 12, 2)->default(0);
            $table->decimal('total_amount', 12, 2);
            $table->text('customer_note')->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->useCurrent();
            $table->index('customer_id');
        });

        Schema::create('order_items', function (Blueprint $table) {
            $table->id()->generatedAs()->always();
            $table->foreignId('order_id')->constrained()->restrictOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->foreignId('variant_id')->nullable()->constrained('product_variants')->nullOnDelete();
            $table->string('product_name', 200);
            $table->jsonb('variant_attributes')->default('{}');
            $table->string('sku', 100);
            $table->decimal('unit_price', 12, 2);
            $table->integer('quantity');
            $table->decimal('line_total', 12, 2);
            $table->timestampTz('created_at')->useCurrent();
            $table->index('order_id');
        });

        Schema::create('payments', function (Blueprint $table) {
            $table->id()->generatedAs()->always();
            $table->foreignId('order_id')->constrained()->restrictOnDelete();
            $table->string('provider', 50);
            $table->string('transaction_id', 150)->nullable();
            $table->decimal('amount', 12, 2);
            $table->char('currency', 3)->default('BDT');
            $table->string('status', 20)->default('pending');
            $table->string('provider_reference', 150)->nullable();
            $table->jsonb('provider_response')->nullable();
            $table->timestampTz('paid_at')->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->useCurrent();
            $table->index('order_id');
        });

        Schema::create('inventory_transactions', function (Blueprint $table) {
            $table->id()->generatedAs()->always();
            $table->foreignId('variant_id')->constrained('product_variants')->restrictOnDelete();
            $table->foreignId('order_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('transaction_type', 20);
            $table->integer('quantity_change');
            $table->text('note')->nullable();
            $table->timestampTz('created_at')->useCurrent();
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE coupons ADD CONSTRAINT coupons_discount_type_check CHECK (discount_type IN ('percentage', 'fixed'))");
            DB::statement('ALTER TABLE coupons ADD CONSTRAINT coupons_discount_value_check CHECK (discount_value > 0)');
            DB::statement('ALTER TABLE coupons ADD CONSTRAINT coupons_max_discount_check CHECK (max_discount IS NULL OR max_discount > 0)');
            DB::statement('ALTER TABLE coupons ADD CONSTRAINT coupons_min_order_amount_check CHECK (min_order_amount >= 0)');
            DB::statement('ALTER TABLE coupons ADD CONSTRAINT coupons_usage_limit_check CHECK (usage_limit IS NULL OR usage_limit > 0)');
            DB::statement('ALTER TABLE coupons ADD CONSTRAINT coupons_usage_count_check CHECK (usage_count >= 0)');
            DB::statement("ALTER TABLE coupons ADD CONSTRAINT coupons_percentage_check CHECK (discount_type <> 'percentage' OR discount_value <= 100)");
            DB::statement('ALTER TABLE coupons ADD CONSTRAINT coupons_date_range_check CHECK (expires_at IS NULL OR starts_at IS NULL OR expires_at > starts_at)');
            DB::statement("ALTER TABLE orders ADD CONSTRAINT orders_status_check CHECK (status IN ('pending', 'confirmed', 'processing', 'shipped', 'delivered', 'cancelled', 'returned'))");
            DB::statement("ALTER TABLE orders ADD CONSTRAINT orders_payment_status_check CHECK (payment_status IN ('unpaid', 'pending', 'paid', 'failed', 'partially_refunded', 'refunded'))");
            DB::statement('ALTER TABLE orders ADD CONSTRAINT orders_amounts_check CHECK (subtotal >= 0 AND discount_amount >= 0 AND shipping_fee >= 0 AND tax_amount >= 0 AND total_amount >= 0)');
            DB::statement('ALTER TABLE order_items ADD CONSTRAINT order_items_amounts_check CHECK (unit_price >= 0 AND line_total >= 0 AND quantity > 0)');
            DB::statement("ALTER TABLE payments ADD CONSTRAINT payments_status_check CHECK (status IN ('pending', 'successful', 'failed', 'refunded'))");
            DB::statement('ALTER TABLE payments ADD CONSTRAINT payments_amount_check CHECK (amount > 0)');
            DB::statement("ALTER TABLE inventory_transactions ADD CONSTRAINT inventory_transactions_type_check CHECK (transaction_type IN ('stock_in', 'stock_out', 'adjustment', 'return'))");
            DB::statement('ALTER TABLE inventory_transactions ADD CONSTRAINT inventory_transactions_quantity_check CHECK (quantity_change <> 0)');
            DB::statement('CREATE UNIQUE INDEX payments_provider_transaction_unique ON payments (provider, transaction_id) WHERE transaction_id IS NOT NULL');
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('inventory_transactions');
        Schema::dropIfExists('payments');
        Schema::dropIfExists('order_items');
        Schema::dropIfExists('orders');
        Schema::dropIfExists('coupons');
    }
};
