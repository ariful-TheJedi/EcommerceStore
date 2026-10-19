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
        Schema::create('products', function (Blueprint $table) {
            $table->id()->generatedAs()->always();
            $table->foreignId('category_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name', 200);
            $table->string('slug', 220)->unique();
            $table->text('description')->nullable();
            $table->string('status', 20)->default('draft');
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->useCurrent();
        });

        DB::statement("ALTER TABLE products ADD CONSTRAINT products_status_check CHECK (status IN ('draft', 'active', 'archived'))");

        Schema::create('product_variants', function (Blueprint $table) {
            $table->id()->generatedAs()->always();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->string('sku', 100)->unique();
            $table->jsonb('attributes')->default('{}');
            $table->decimal('price', 12, 2);
            $table->decimal('compare_at_price', 12, 2)->nullable();
            $table->integer('stock_quantity')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->useCurrent();
            $table->unique(['product_id', 'id']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE product_variants ADD CONSTRAINT product_variants_attributes_object_check CHECK (jsonb_typeof(attributes) = 'object')");
            DB::statement('ALTER TABLE product_variants ADD CONSTRAINT product_variants_price_check CHECK (price >= 0)');
            DB::statement('ALTER TABLE product_variants ADD CONSTRAINT product_variants_compare_at_price_check CHECK (compare_at_price >= 0)');
            DB::statement('ALTER TABLE product_variants ADD CONSTRAINT product_variants_stock_quantity_check CHECK (stock_quantity >= 0)');
        }

        Schema::create('product_images', function (Blueprint $table) {
            $table->id()->generatedAs()->always();
            $table->foreignId('product_id')->constrained();
            $table->unsignedBigInteger('variant_id')->nullable();
            $table->string('image_path', 500);
            $table->string('alt_text', 255)->nullable();
            $table->boolean('is_primary')->default(false);
            $table->integer('sort_order')->default(0);
            $table->timestampTz('created_at')->useCurrent();
            $table->foreign(['product_id', 'variant_id'])->references(['product_id', 'id'])->on('product_variants');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('CREATE UNIQUE INDEX product_images_one_primary_per_product ON product_images (product_id) WHERE is_primary = TRUE');
        }

        Schema::create('tags', function (Blueprint $table) {
            $table->id()->generatedAs()->always();
            $table->string('name', 100);
            $table->string('slug', 120)->unique();
            $table->timestampTz('created_at')->useCurrent();
        });

        Schema::create('product_tags', function (Blueprint $table) {
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('tag_id')->constrained()->cascadeOnDelete();
            $table->primary(['product_id', 'tag_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('product_tags');
        Schema::dropIfExists('tags');
        Schema::dropIfExists('product_images');
        Schema::dropIfExists('product_variants');
        Schema::dropIfExists('products');
    }
};
