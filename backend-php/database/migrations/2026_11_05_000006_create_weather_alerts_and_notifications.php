<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('weather_alerts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('provider_event_id', 180);
            $table->string('county', 100);
            $table->string('hazard', 32);
            $table->string('severity', 16);
            $table->jsonb('details');
            $table->timestampTz('observed_at');
            $table->timestampTz('created_at')->useCurrent();
            $table->unique(['provider_event_id', 'hazard']);
            $table->index(['county', 'created_at']);
        });

        Schema::create('farm_notifications', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignUuid('weather_alert_id')->constrained('weather_alerts')->cascadeOnDelete();
            $table->string('type', 32);
            $table->string('title', 160);
            $table->string('body', 500);
            $table->timestampTz('read_at')->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->unique(['user_id', 'weather_alert_id']);
            $table->index(['user_id', 'read_at', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('farm_notifications');
        Schema::dropIfExists('weather_alerts');
    }
};
