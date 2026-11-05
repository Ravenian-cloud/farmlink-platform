<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pilot_feedback', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('category', 24);
            $table->unsignedSmallInteger('rating')->nullable();
            $table->unsignedSmallInteger('negotiation_rating')->nullable();
            $table->unsignedSmallInteger('recommendation_accuracy_rating')->nullable();
            $table->string('summary', 160)->nullable();
            $table->text('details');
            $table->string('app_version', 40)->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->index(['category', 'created_at']);
            $table->index(['user_id', 'created_at']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE pilot_feedback ADD CONSTRAINT pilot_feedback_category_check CHECK (category IN ('bug', 'negotiation', 'recommendation', 'general'))");
            DB::statement('ALTER TABLE pilot_feedback ADD CONSTRAINT pilot_feedback_ratings_check CHECK ((rating IS NULL OR rating BETWEEN 1 AND 5) AND (negotiation_rating IS NULL OR negotiation_rating BETWEEN 1 AND 5) AND (recommendation_accuracy_rating IS NULL OR recommendation_accuracy_rating BETWEEN 1 AND 5))');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('pilot_feedback');
    }
};
