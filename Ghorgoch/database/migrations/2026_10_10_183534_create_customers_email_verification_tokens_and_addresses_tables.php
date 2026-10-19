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
        Schema::create('customers', function (Blueprint $table) {
            $table->id()->generatedAs()->always();
            $table->foreignId('user_id')->unique()->constrained();
            $table->date('date_of_birth')->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->useCurrent();
        });

        Schema::create('email_verification_tokens', function (Blueprint $table) {
            $table->id()->generatedAs()->always();
            $table->foreignId('user_id')->unique()->constrained();
            $table->string('token_hash', 255)->unique();
            $table->timestampTz('expires_at');
            $table->timestampTz('created_at')->useCurrent();
        });

        Schema::create('addresses', function (Blueprint $table) {
            $table->id()->generatedAs()->always();
            $table->foreignId('customer_id')->constrained();
            $table->index('customer_id');
            $table->string('label', 50)->nullable();
            $table->string('recipient_name', 150);
            $table->string('phone', 20);
            $table->string('address_line1', 255);
            $table->string('address_line2', 255)->nullable();
            $table->string('area', 100)->nullable();
            $table->string('city', 100);
            $table->string('district', 100)->nullable();
            $table->string('postal_code', 20)->nullable();
            $table->char('country_code', 2)->default('BD');
            $table->boolean('is_default')->default(false);
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->useCurrent();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('addresses');
        Schema::dropIfExists('email_verification_tokens');
        Schema::dropIfExists('customers');
    }
};
