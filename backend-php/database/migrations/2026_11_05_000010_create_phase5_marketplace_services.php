<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE users DROP CONSTRAINT users_role_check");
            DB::statement('ALTER TABLE users ALTER COLUMN role TYPE varchar(24)');
            DB::statement("ALTER TABLE users ADD CONSTRAINT users_role_check CHECK (role IN ('farmer', 'buyer', 'supplier', 'transport_provider'))");
        }

        Schema::create('subscriptions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('plan', 32);
            $table->string('status', 16)->default('inactive');
            $table->timestampTz('starts_at')->nullable();
            $table->timestampTz('ends_at')->nullable();
            $table->timestampsTz();
            $table->index(['status', 'ends_at']);
        });

        Schema::create('ad_campaigns', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('advertiser_id')->constrained('users');
            $table->string('name', 120);
            $table->string('target_role', 24);
            $table->string('creative_path', 500);
            $table->string('destination_url', 2048);
            $table->string('status', 20)->default('draft');
            $table->timestampTz('starts_at')->nullable();
            $table->timestampTz('ends_at')->nullable();
            $table->unsignedBigInteger('impressions_count')->default(0);
            $table->unsignedBigInteger('clicks_count')->default(0);
            $table->timestampsTz();
            $table->index(['status', 'target_role', 'starts_at', 'ends_at']);
            $table->index(['advertiser_id', 'status']);
        });

        Schema::create('mpesa_purchase_intents', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('subscription_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignUuid('ad_campaign_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->bigInteger('amount_minor');
            $table->char('currency', 3)->default('KES');
            $table->string('status', 16)->default('initiated');
            $table->string('checkout_request_id', 100)->nullable()->unique();
            $table->string('merchant_request_id', 100)->nullable();
            $table->string('receipt', 32)->nullable()->unique();
            $table->timestampTz('paid_at')->nullable();
            $table->timestampsTz();
            $table->index(['user_id', 'status', 'created_at']);
        });

        Schema::create('transport_services', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('provider_id')->unique()->constrained('users')->cascadeOnDelete();
            $table->string('county', 100);
            $table->string('service_area', 160)->nullable();
            $table->string('vehicle_type', 80);
            $table->decimal('capacity_kg', 12, 2)->nullable();
            $table->bigInteger('base_rate_minor')->nullable();
            $table->char('currency', 3)->default('KES');
            $table->boolean('available')->default(true);
            $table->timestampsTz();
            $table->index(['county', 'available']);
        });

        Schema::create('deliveries', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('transaction_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignUuid('provider_id')->nullable()->constrained('users');
            $table->string('status', 24)->default('requested');
            $table->string('pickup_county', 100);
            $table->string('delivery_county', 100);
            $table->timestampTz('estimated_pickup_at')->nullable();
            $table->timestampTz('estimated_delivery_at')->nullable();
            $table->timestampTz('picked_up_at')->nullable();
            $table->timestampTz('delivered_at')->nullable();
            $table->timestampsTz();
            $table->index(['provider_id', 'status']);
        });

        Schema::create('policy_partners', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('name', 180);
            $table->json('counties');
            $table->string('key_hash', 64)->unique();
            $table->timestampTz('last_used_at')->nullable();
            $table->timestampTz('revoked_at')->nullable();
            $table->timestampsTz();
        });

        Schema::create('ad_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignUuid('campaign_id')->constrained('ad_campaigns')->cascadeOnDelete();
            $table->string('event_type', 12);
            $table->string('visitor_hash', 64);
            $table->date('event_date');
            $table->timestampTz('created_at')->useCurrent();
            $table->index(['campaign_id', 'event_type', 'created_at']);
            $table->index(['campaign_id', 'visitor_hash', 'created_at']);
            $table->unique(['campaign_id', 'event_type', 'visitor_hash', 'event_date'], 'ad_events_daily_dedupe');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE subscriptions ADD CONSTRAINT subscriptions_status_check CHECK (status IN ('inactive', 'active', 'expired', 'cancelled'))");
            DB::statement("ALTER TABLE mpesa_purchase_intents ADD CONSTRAINT purchase_intent_target_check CHECK ((subscription_id IS NOT NULL AND ad_campaign_id IS NULL) OR (subscription_id IS NULL AND ad_campaign_id IS NOT NULL))");
            DB::statement("ALTER TABLE mpesa_purchase_intents ADD CONSTRAINT purchase_intent_status_check CHECK (status IN ('initiated', 'pending', 'succeeded', 'failed', 'cancelled'))");
            DB::statement("ALTER TABLE mpesa_purchase_intents ADD CONSTRAINT purchase_intent_amount_check CHECK (amount_minor >= 100 AND amount_minor % 100 = 0 AND currency = 'KES')");
            DB::statement("ALTER TABLE ad_campaigns ADD CONSTRAINT ad_campaign_status_check CHECK (status IN ('draft', 'pending_review', 'active', 'rejected', 'paused', 'completed'))");
            DB::statement("ALTER TABLE ad_campaigns ADD CONSTRAINT ad_campaign_role_check CHECK (target_role IN ('farmer', 'buyer', 'supplier'))");
            DB::statement("ALTER TABLE deliveries ADD CONSTRAINT delivery_status_check CHECK (status IN ('requested', 'assigned', 'picked_up', 'in_transit', 'delivered', 'cancelled'))");
            DB::statement("ALTER TABLE ad_events ADD CONSTRAINT ad_events_type_check CHECK (event_type IN ('impression', 'click'))");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql' && DB::table('users')->where('role', 'transport_provider')->exists()) {
            throw new \RuntimeException('Remove or reassign transport provider accounts before rolling back Phase 5.');
        }

        Schema::dropIfExists('ad_events');
        Schema::dropIfExists('policy_partners');
        Schema::dropIfExists('deliveries');
        Schema::dropIfExists('transport_services');
        Schema::dropIfExists('mpesa_purchase_intents');
        Schema::dropIfExists('ad_campaigns');
        Schema::dropIfExists('subscriptions');
        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE users DROP CONSTRAINT users_role_check");
            DB::statement('ALTER TABLE users ALTER COLUMN role TYPE varchar(16)');
            DB::statement("ALTER TABLE users ADD CONSTRAINT users_role_check CHECK (role IN ('farmer', 'buyer', 'supplier'))");
        }
    }
};
