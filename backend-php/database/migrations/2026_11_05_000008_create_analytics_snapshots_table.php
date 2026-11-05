<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('analytics_snapshots', function (Blueprint $table): void {
            $table->id();
            $table->string('type', 40);
            $table->char('snapshot_key', 64)->unique();
            $table->json('dimensions');
            $table->json('payload');
            $table->timestampTz('computed_at');
            $table->timestampsTz();
            $table->index(['type', 'computed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('analytics_snapshots');
    }
};
