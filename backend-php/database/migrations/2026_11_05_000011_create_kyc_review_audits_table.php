<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kyc_review_audits', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('reviewer_id')->constrained('users')->restrictOnDelete();
            $table->foreignUuid('target_user_id')->constrained('users')->restrictOnDelete();
            $table->string('previous_status', 16);
            $table->string('new_status', 16);
            $table->text('reason');
            $table->timestampTz('reviewed_at');
            $table->index(['target_user_id', 'reviewed_at']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE kyc_review_audits ADD CONSTRAINT kyc_review_audits_previous_status_check CHECK (previous_status IN ('pending', 'verified', 'rejected'))");
            DB::statement("ALTER TABLE kyc_review_audits ADD CONSTRAINT kyc_review_audits_new_status_check CHECK (new_status IN ('pending', 'verified', 'rejected'))");
            DB::unprepared(<<<'SQL'
                CREATE FUNCTION prevent_kyc_review_audit_mutation() RETURNS trigger AS $$
                BEGIN
                    RAISE EXCEPTION 'KYC review audit records are append-only';
                END;
                $$ LANGUAGE plpgsql
                SQL);
            DB::unprepared('CREATE TRIGGER kyc_review_audits_no_update BEFORE UPDATE OR DELETE ON kyc_review_audits FOR EACH ROW EXECUTE FUNCTION prevent_kyc_review_audit_mutation()');
        } elseif (DB::getDriverName() === 'sqlite') {
            DB::unprepared("CREATE TRIGGER kyc_review_audits_no_update BEFORE UPDATE ON kyc_review_audits BEGIN SELECT RAISE(ABORT, 'KYC review audit records are append-only'); END");
            DB::unprepared("CREATE TRIGGER kyc_review_audits_no_delete BEFORE DELETE ON kyc_review_audits BEGIN SELECT RAISE(ABORT, 'KYC review audit records are append-only'); END");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared('DROP TRIGGER IF EXISTS kyc_review_audits_no_update ON kyc_review_audits');
            DB::unprepared('DROP FUNCTION IF EXISTS prevent_kyc_review_audit_mutation()');
        } elseif (DB::getDriverName() === 'sqlite') {
            DB::unprepared('DROP TRIGGER IF EXISTS kyc_review_audits_no_update');
            DB::unprepared('DROP TRIGGER IF EXISTS kyc_review_audits_no_delete');
        }

        Schema::dropIfExists('kyc_review_audits');
    }
};
