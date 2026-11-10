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
        Schema::table('orders', function (Blueprint $table): void {
            $table->unsignedBigInteger('customer_id')->nullable()->change();
            $table->string('guest_name', 150)->nullable();
            $table->string('guest_email', 255)->nullable();
            $table->string('guest_phone', 20)->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::table('orders')->whereNull('customer_id')->exists()) {
            throw new RuntimeException('Guest orders must be retained; this migration cannot be rolled back while they exist.');
        }
        Schema::table('orders', function (Blueprint $table): void {
            $table->dropColumn(['guest_name', 'guest_email', 'guest_phone']);
            $table->unsignedBigInteger('customer_id')->nullable(false)->change();
        });
    }
};
