<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('name', 160);
            $table->string('phone', 32)->unique();
            $table->string('email', 254)->nullable()->unique();
            $table->string('password');
            $table->string('role', 16);
            $table->string('kyc_status', 16)->default('pending');
            $table->string('locale', 12)->default('en');
            $table->timestampTz('phone_verified_at')->nullable();
            $table->rememberToken();
            $table->timestampsTz();
            $table->softDeletesTz();
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE users ADD CONSTRAINT users_role_check CHECK (role IN ('farmer', 'buyer', 'supplier'))");
            DB::statement("ALTER TABLE users ADD CONSTRAINT users_kyc_status_check CHECK (kyc_status IN ('pending', 'verified', 'rejected'))");
        }

        Schema::create('user_profiles', function (Blueprint $table): void {
            $table->foreignUuid('user_id')->primary()->constrained()->cascadeOnDelete();
            $table->string('farm_name', 160)->nullable();
            $table->string('business_name', 160)->nullable();
            $table->string('county', 100)->nullable();
            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_profiles');
        Schema::dropIfExists('users');
    }
};
