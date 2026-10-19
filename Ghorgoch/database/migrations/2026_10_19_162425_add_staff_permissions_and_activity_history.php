<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->json('permissions')->nullable();
            $table->index(['role', 'is_active', 'id']);
        });

        Schema::create('staff_security_locks', function (Blueprint $table): void {
            $table->unsignedInteger('id')->primary();
        });
        DB::table('staff_security_locks')->insert(['id' => 1]);

        Schema::table('orders', function (Blueprint $table): void {
            $table->foreignId('processed_by')->nullable()->constrained('users')->restrictOnDelete();
        });
        Schema::table('payments', function (Blueprint $table): void {
            $table->foreignId('recorded_by')->nullable()->constrained('users')->restrictOnDelete();
        });

        Schema::create('staff_activity', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('actor_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('staff_id')->constrained('users')->restrictOnDelete();
            $table->string('action', 40);
            $table->json('before')->nullable();
            $table->json('after');
            $table->timestampTz('created_at')->useCurrent();
            $table->index(['staff_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('staff_activity');
        Schema::dropIfExists('staff_security_locks');
        Schema::table('payments', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('recorded_by');
        });
        Schema::table('orders', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('processed_by');
        });
        Schema::table('users', function (Blueprint $table): void {
            $table->dropIndex(['role', 'is_active', 'id']);
            $table->dropColumn('permissions');
        });
    }
};
