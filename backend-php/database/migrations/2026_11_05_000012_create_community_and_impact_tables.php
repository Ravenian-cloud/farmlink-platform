<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('knowledge_articles', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('author_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('title', 180);
            $table->string('slug', 200)->unique();
            $table->string('summary', 500);
            $table->longText('body');
            $table->string('content_type', 16)->default('article');
            $table->string('video_url', 2048)->nullable();
            $table->string('status', 16)->default('draft');
            $table->timestampTz('published_at')->nullable();
            $table->timestampsTz();
            $table->index(['status', 'content_type', 'published_at']);
        });

        Schema::create('forum_threads', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('author_id')->constrained('users')->cascadeOnDelete();
            $table->string('title', 180);
            $table->text('body');
            $table->string('status', 16)->default('published');
            $table->timestampTz('moderated_at')->nullable();
            $table->timestampsTz();
            $table->index(['status', 'created_at']);
            $table->index(['author_id', 'created_at']);
        });

        Schema::create('forum_posts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('thread_id')->constrained('forum_threads')->cascadeOnDelete();
            $table->foreignUuid('parent_id')->nullable()->constrained('forum_posts')->cascadeOnDelete();
            $table->foreignUuid('author_id')->constrained('users')->cascadeOnDelete();
            $table->text('body');
            $table->string('status', 16)->default('published');
            $table->timestampTz('moderated_at')->nullable();
            $table->timestampsTz();
            $table->index(['thread_id', 'status', 'created_at']);
            $table->index(['parent_id', 'created_at']);
        });

        Schema::create('farmer_impact_observations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('farmer_id')->constrained('users')->cascadeOnDelete();
            $table->date('reporting_period');
            $table->string('county', 100);
            $table->bigInteger('baseline_monthly_income_minor');
            $table->bigInteger('current_monthly_income_minor');
            $table->decimal('baseline_post_harvest_loss_percent', 5, 2);
            $table->decimal('current_post_harvest_loss_percent', 5, 2);
            $table->char('currency', 3)->default('KES');
            $table->timestampTz('consented_at');
            $table->timestampsTz();
            $table->unique(['farmer_id', 'reporting_period']);
            $table->index(['county', 'reporting_period']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE knowledge_articles ADD CONSTRAINT knowledge_articles_type_check CHECK (content_type IN ('article', 'tutorial'))");
            DB::statement("ALTER TABLE knowledge_articles ADD CONSTRAINT knowledge_articles_status_check CHECK (status IN ('draft', 'published', 'archived'))");
            DB::statement("ALTER TABLE forum_threads ADD CONSTRAINT forum_threads_status_check CHECK (status IN ('published', 'hidden', 'removed'))");
            DB::statement("ALTER TABLE forum_posts ADD CONSTRAINT forum_posts_status_check CHECK (status IN ('published', 'hidden', 'removed'))");
            DB::statement('ALTER TABLE farmer_impact_observations ADD CONSTRAINT farmer_impact_income_check CHECK (baseline_monthly_income_minor >= 0 AND current_monthly_income_minor >= 0)');
            DB::statement('ALTER TABLE farmer_impact_observations ADD CONSTRAINT farmer_impact_loss_check CHECK (baseline_post_harvest_loss_percent BETWEEN 0 AND 100 AND current_post_harvest_loss_percent BETWEEN 0 AND 100)');
            DB::statement("ALTER TABLE farmer_impact_observations ADD CONSTRAINT farmer_impact_currency_check CHECK (currency = 'KES')");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('farmer_impact_observations');
        Schema::dropIfExists('forum_posts');
        Schema::dropIfExists('forum_threads');
        Schema::dropIfExists('knowledge_articles');
    }
};
