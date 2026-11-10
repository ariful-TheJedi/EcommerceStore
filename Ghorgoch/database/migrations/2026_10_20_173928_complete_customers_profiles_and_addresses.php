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
        Schema::table('customers', function (Blueprint $table): void {
            $table->timestampTz('blocked_at')->nullable();
            $table->text('blocked_reason')->nullable();
            $table->foreignId('blocked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('closed_at')->nullable();
        });
        Schema::table('addresses', function (Blueprint $table): void {
            $table->string('division', 100)->nullable();
            $table->string('upazila', 100)->nullable();
            $table->text('delivery_instructions')->nullable();
        });
        DB::table('customers')->select('id')->orderBy('id')->chunkById(500, function ($customers): void {
            foreach ($customers as $customer) {
                $addresses = DB::table('addresses')->where('customer_id', $customer->id);
                $first = (clone $addresses)->orderByDesc('is_default')->orderBy('id')->value('id');
                if ($first !== null) {
                    (clone $addresses)->update(['is_default' => false]);
                    (clone $addresses)->where('id', $first)->update(['is_default' => true]);
                }
            }
        });
        DB::statement('CREATE UNIQUE INDEX addresses_one_default_per_customer ON addresses (customer_id) WHERE is_default = true');
        Schema::create('customer_status_history', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('customer_id')->constrained()->restrictOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action', 20);
            $table->text('reason');
            $table->timestampTz('created_at')->useCurrent();
            $table->index(['customer_id', 'id']);
        });
        Schema::create('customer_notes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('customer_id')->constrained()->restrictOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('body');
            $table->timestampTz('created_at')->useCurrent();
            $table->index(['customer_id', 'id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('customer_notes');
        Schema::dropIfExists('customer_status_history');
        DB::statement('DROP INDEX IF EXISTS addresses_one_default_per_customer');
        Schema::table('addresses', function (Blueprint $table): void {
            $table->dropColumn(['division', 'upazila', 'delivery_instructions']);
        });
        Schema::table('customers', function (Blueprint $table): void {
            $table->dropForeign(['blocked_by']);
            $table->dropColumn(['blocked_at', 'blocked_reason', 'blocked_by', 'closed_at']);
        });
    }
};
