<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('seller_id')->constrained('users');
            $table->string('category', 12);
            $table->string('name', 180);
            $table->text('description')->default('');
            $table->string('crop', 100)->nullable();
            $table->string('input_type', 100)->nullable();
            $table->string('brand', 120)->nullable();
            $table->decimal('quantity_available', 14, 3);
            $table->string('unit', 24);
            $table->bigInteger('price_minor');
            $table->char('currency', 3)->default('KES');
            $table->string('county', 100)->nullable();
            $table->string('status', 12)->default('active');
            $table->timestampsTz();
            $table->index(['category', 'status', 'created_at'], 'products_browse_index');
            $table->index(['seller_id', 'status'], 'products_seller_status_index');
            $table->index(['county', 'category', 'status'], 'products_county_category_index');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE products ADD CONSTRAINT products_category_check CHECK (category IN ('produce', 'input'))");
            DB::statement("ALTER TABLE products ADD CONSTRAINT products_status_check CHECK (status IN ('draft', 'active', 'paused', 'sold_out', 'archived'))");
            DB::statement('ALTER TABLE products ADD CONSTRAINT products_quantity_check CHECK (quantity_available > 0)');
            DB::statement("ALTER TABLE products ADD CONSTRAINT products_price_check CHECK (price_minor > 0 AND currency = 'KES')");
            DB::statement("ALTER TABLE products ADD CONSTRAINT products_kind_check CHECK ((category = 'produce' AND crop IS NOT NULL AND input_type IS NULL) OR (category = 'input' AND input_type IS NOT NULL AND crop IS NULL))");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
