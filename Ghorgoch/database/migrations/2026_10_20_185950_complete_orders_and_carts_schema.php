<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('carts', function (Blueprint $table): void {
            $table->unsignedBigInteger('customer_id')->nullable()->change();
            $table->string('guest_key', 64)->nullable()->unique();
            $table->timestampTz('expires_at')->nullable();
            $table->timestampTz('converted_at')->nullable();
        });
        Schema::table('orders', function (Blueprint $table): void {
            $table->string('checkout_key', 64)->nullable()->unique();
            $table->string('checkout_fingerprint', 64)->nullable();
            $table->string('guest_token_hash', 64)->nullable()->unique();
            $table->json('contact_snapshot')->nullable();
            $table->char('currency', 3)->default('BDT');
            $table->string('payment_method', 20)->default('cod');
            $table->string('delivery_method', 30)->default('standard');
            $table->string('courier_name', 100)->nullable();
            $table->string('tracking_reference', 150)->nullable();
            $table->string('delivery_status', 30)->default('pending');
            foreach (['confirmed', 'processing', 'shipped', 'delivered', 'cancelled'] as $status) {
                $table->timestampTz($status.'_at')->nullable();
            }
            $table->index(['status', 'created_at', 'id']);
            $table->index(['payment_status', 'created_at', 'id']);
        });
        Schema::table('order_items', function (Blueprint $table): void {
            $table->decimal('discount_amount', 12, 2)->default(0);
            $table->char('currency', 3)->default('BDT');
        });
        Schema::table('payments', function (Blueprint $table): void {
            $table->string('obligation_status', 30)->default('open');
        });
        Schema::create('order_history', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('order_id')->constrained()->restrictOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action', 40);
            $table->string('previous_status', 20)->nullable();
            $table->string('new_status', 20);
            $table->text('note')->nullable();
            $table->json('before')->nullable();
            $table->json('after')->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->index(['order_id', 'id']);
        });
    }

    public function down(): void
    {
        if (DB::table('orders')->whereNotNull('checkout_key')->exists() || DB::table('carts')->whereNull('customer_id')->exists()) {
            throw new RuntimeException('Checkout and guest cart records must be preserved. Use a forward migration.');
        }
        Schema::dropIfExists('order_history');
        Schema::table('payments', fn (Blueprint $table) => $table->dropColumn('obligation_status'));
        Schema::table('order_items', fn (Blueprint $table) => $table->dropColumn(['discount_amount', 'currency']));
        Schema::table('orders', function (Blueprint $table): void {
            $table->dropIndex(['status', 'created_at', 'id']);
            $table->dropIndex(['payment_status', 'created_at', 'id']);
            $table->dropUnique(['checkout_key']);
            $table->dropUnique(['guest_token_hash']);
            $table->dropColumn(['checkout_key', 'checkout_fingerprint', 'guest_token_hash', 'contact_snapshot', 'currency', 'payment_method', 'delivery_method', 'courier_name', 'tracking_reference', 'delivery_status', 'confirmed_at', 'processing_at', 'shipped_at', 'delivered_at', 'cancelled_at']);
        });
        Schema::table('carts', function (Blueprint $table): void {
            $table->dropUnique(['guest_key']);
            $table->dropColumn(['guest_key', 'expires_at', 'converted_at']);
            $table->unsignedBigInteger('customer_id')->nullable(false)->change();
        });
    }
};
