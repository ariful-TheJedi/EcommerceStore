<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('delivery_charge_settings', function (Blueprint $table): void {
            $table->unsignedInteger('id')->primary();
            $table->decimal('inside_dhaka', 12, 2);
            $table->decimal('outside_dhaka', 12, 2);
            $table->unsignedInteger('version')->default(0);
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
        DB::table('delivery_charge_settings')->insert(['id' => 1, 'inside_dhaka' => '60.00', 'outside_dhaka' => '120.00', 'version' => 0, 'created_at' => now(), 'updated_at' => now()]);
    }

    public function down(): void
    {
        Schema::dropIfExists('delivery_charge_settings');
    }
};
