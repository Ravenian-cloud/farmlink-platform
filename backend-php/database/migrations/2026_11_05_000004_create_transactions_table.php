<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transactions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('bid_id')->unique()->constrained('bids');
            $table->foreignUuid('product_id')->constrained('products');
            $table->foreignUuid('buyer_id')->constrained('users');
            $table->foreignUuid('seller_id')->constrained('users');
            $table->decimal('quantity', 14, 3);
            $table->string('unit', 24);
            $table->bigInteger('unit_price_minor');
            $table->bigInteger('total_minor');
            $table->char('currency', 3);
            $table->string('status', 20)->default('awaiting_payment');
            $table->string('payment_status', 16)->default('initiated');
            $table->string('escrow_status', 16)->default('not_funded');
            $table->string('checkout_request_id', 100)->nullable()->unique();
            $table->string('merchant_request_id', 100)->nullable();
            $table->string('mpesa_receipt', 32)->nullable()->unique();
            $table->timestampTz('paid_at')->nullable();
            $table->timestampTz('delivered_at')->nullable();
            $table->timestampsTz();
            $table->index(['buyer_id', 'status', 'created_at']);
            $table->index(['seller_id', 'status', 'created_at']);
        });
        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE transactions ADD CONSTRAINT transactions_status_check CHECK (status IN ('awaiting_payment', 'escrow_held', 'completed', 'cancelled', 'disputed'))");
            DB::statement("ALTER TABLE transactions ADD CONSTRAINT transactions_payment_status_check CHECK (payment_status IN ('initiated', 'pending', 'succeeded', 'failed', 'cancelled'))");
            DB::statement("ALTER TABLE transactions ADD CONSTRAINT transactions_escrow_status_check CHECK (escrow_status IN ('not_funded', 'held', 'released', 'disputed'))");
            DB::statement("ALTER TABLE transactions ADD CONSTRAINT transactions_amount_check CHECK (quantity > 0 AND unit_price_minor > 0 AND total_minor >= 100 AND total_minor % 100 = 0 AND currency = 'KES')");
        }

        Schema::create('mpesa_callback_events', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('checkout_request_id', 100)->unique();
            $table->string('result_code', 16);
            $table->string('receipt', 32)->nullable();
            $table->timestampTz('received_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mpesa_callback_events');
        Schema::dropIfExists('transactions');
    }
};
