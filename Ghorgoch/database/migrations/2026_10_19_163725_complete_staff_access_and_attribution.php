<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->unsignedBigInteger('access_version')->default(0);
        });
        Schema::table('payments', function (Blueprint $table): void {
            $table->foreignId('reconciled_by')->nullable()->constrained('users')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('reconciled_by');
        });
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('access_version');
        });
    }
};
