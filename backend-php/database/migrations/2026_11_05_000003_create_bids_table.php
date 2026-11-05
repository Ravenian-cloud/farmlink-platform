<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bids', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('product_id')->constrained('products');
            $table->foreignUuid('buyer_id')->constrained('users');
            $table->foreignUuid('seller_id')->constrained('users');
            $table->string('status', 12)->default('pending');
            $table->timestampTz('expires_at')->nullable();
            $table->timestampsTz();
            $table->index(['buyer_id', 'status', 'updated_at']);
            $table->index(['seller_id', 'status', 'updated_at']);
        });
        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE bids ADD CONSTRAINT bids_status_check CHECK (status IN ('pending', 'accepted', 'rejected', 'withdrawn', 'expired'))");
            DB::statement('ALTER TABLE bids ADD CONSTRAINT bids_participants_check CHECK (buyer_id <> seller_id)');
        }

        Schema::create('bid_revisions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('bid_id')->constrained('bids')->cascadeOnDelete();
            $table->foreignUuid('created_by')->constrained('users');
            $table->integer('revision_number');
            $table->decimal('quantity', 14, 3);
            $table->bigInteger('unit_price_minor');
            $table->char('currency', 3);
            $table->string('message', 1000)->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->unique(['bid_id', 'revision_number']);
        });
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE bid_revisions ADD CONSTRAINT bid_revisions_quantity_check CHECK (quantity > 0)');
            DB::statement('ALTER TABLE bid_revisions ADD CONSTRAINT bid_revisions_price_check CHECK (unit_price_minor > 0 AND currency = \'KES\')');
            DB::statement('ALTER TABLE bid_revisions ADD CONSTRAINT bid_revisions_revision_number_check CHECK (revision_number > 0)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('bid_revisions');
        Schema::dropIfExists('bids');
    }
};
