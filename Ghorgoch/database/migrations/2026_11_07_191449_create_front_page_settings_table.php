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
        Schema::create('front_page_settings', function (Blueprint $table): void {
            $table->id();
            $table->json('content')->nullable();
            $table->unsignedInteger('version')->default(0);
            $table->timestamps();
        });
        DB::table('front_page_settings')->insert(['id' => 1, 'content' => null, 'version' => 0, 'created_at' => now(), 'updated_at' => now()]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('front_page_settings');
    }
};
