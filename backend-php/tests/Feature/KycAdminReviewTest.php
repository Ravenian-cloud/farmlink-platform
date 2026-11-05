<?php

namespace Tests\Feature;

use App\Models\User;
use Firebase\JWT\JWT;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class KycAdminReviewTest extends TestCase
{
    use RefreshDatabase;

    public function test_review_requires_a_jwt_and_an_allowlisted_administrator(): void
    {
        $target = $this->createUser();
        $this->patchJson("/api/v1/admin/kyc/users/{$target->id}/review", [
            'status' => 'verified',
            'reason' => 'Documents reviewed by staff',
        ])->assertUnauthorized();

        $nonAdmin = $this->createUser();
        config(['services.kyc.admin_ids' => (string) Str::uuid()]);

        $this->withToken($this->tokenFor($nonAdmin))
            ->patchJson("/api/v1/admin/kyc/users/{$target->id}/review", [
                'status' => 'verified',
                'reason' => 'Documents reviewed by staff',
            ])
            ->assertForbidden();

        $this->assertDatabaseCount('kyc_review_audits', 0);
    }

    public function test_administrator_cannot_review_their_own_account(): void
    {
        $admin = $this->createUser();
        config(['services.kyc.admin_ids' => (string) $admin->id]);

        $this->withToken($this->tokenFor($admin))
            ->patchJson('/api/v1/admin/kyc/users/' . strtoupper((string) $admin->id) . '/review', [
                'status' => 'verified',
                'reason' => 'Reviewed supporting documentation',
            ])
            ->assertForbidden();

        $this->assertSame('pending', $admin->fresh()->kyc_status);
        $this->assertDatabaseCount('kyc_review_audits', 0);
    }

    public function test_admin_can_make_only_allowed_transitions_and_each_change_is_audited(): void
    {
        $admin = $this->createUser();
        $verifiedTarget = $this->createUser();
        $rejectedTarget = $this->createUser();
        config(['services.kyc.admin_ids' => (string) $admin->id]);
        $token = $this->tokenFor($admin);

        $verifiedResponse = $this->withToken($token)
            ->patchJson("/api/v1/admin/kyc/users/{$verifiedTarget->id}/review", [
                'status' => 'verified',
                'reason' => 'Identity evidence checked by reviewer',
            ])
            ->assertOk()
            ->assertJsonPath('data.kyc_status', 'verified');

        $this->withToken($token)
            ->patchJson("/api/v1/admin/kyc/users/{$rejectedTarget->id}/review", [
                'status' => 'rejected',
                'reason' => 'Submitted evidence does not match the account',
            ])
            ->assertOk()
            ->assertJsonPath('data.kyc_status', 'rejected');

        $this->withToken($token)
            ->patchJson("/api/v1/admin/kyc/users/{$rejectedTarget->id}/review", [
                'status' => 'pending',
                'reason' => 'User requested a fresh review with new evidence',
            ])
            ->assertOk()
            ->assertJsonPath('data.kyc_status', 'pending');

        $this->assertSame('verified', $verifiedTarget->fresh()->kyc_status);
        $this->assertSame('pending', $rejectedTarget->fresh()->kyc_status);
        $this->assertDatabaseCount('kyc_review_audits', 3);

        $audit = DB::table('kyc_review_audits')->where('id', $verifiedResponse->json('data.audit_id'))->first();
        $this->assertNotNull($audit);
        $this->assertSame((string) $admin->id, $audit->reviewer_id);
        $this->assertSame((string) $verifiedTarget->id, $audit->target_user_id);
        $this->assertSame('pending', $audit->previous_status);
        $this->assertSame('verified', $audit->new_status);
        $this->assertSame('Identity evidence checked by reviewer', $audit->reason);
        $this->assertNotNull($audit->reviewed_at);

        $this->withToken($token)
            ->patchJson("/api/v1/admin/kyc/users/{$verifiedTarget->id}/review", [
                'status' => 'rejected',
                'reason' => 'A verified account cannot transition directly',
            ])
            ->assertUnprocessable();
        $this->assertDatabaseCount('kyc_review_audits', 3);
    }

    public function test_review_rejects_identity_and_role_fields(): void
    {
        $admin = $this->createUser();
        $target = $this->createUser();
        config(['services.kyc.admin_ids' => (string) $admin->id]);

        $this->withToken($this->tokenFor($admin))
            ->patchJson("/api/v1/admin/kyc/users/{$target->id}/review", [
                'status' => 'verified',
                'reason' => 'Identity evidence checked by reviewer',
                'role' => 'admin',
                'name' => 'Changed identity',
            ])
            ->assertUnprocessable();

        $this->assertSame('pending', $target->fresh()->kyc_status);
        $this->assertSame($target->name, $target->fresh()->name);
        $this->assertSame('farmer', $target->fresh()->role);
        $this->assertDatabaseCount('kyc_review_audits', 0);
    }

    public function test_audit_entries_cannot_be_updated_or_deleted(): void
    {
        $admin = $this->createUser();
        $target = $this->createUser();
        config(['services.kyc.admin_ids' => (string) $admin->id]);

        $auditId = $this->withToken($this->tokenFor($admin))
            ->patchJson("/api/v1/admin/kyc/users/{$target->id}/review", [
                'status' => 'rejected',
                'reason' => 'Submitted evidence does not match the account',
            ])
            ->assertOk()
            ->json('data.audit_id');

        try {
            DB::table('kyc_review_audits')->where('id', $auditId)->update(['reason' => 'Changed audit record']);
            $this->fail('Updating an audit entry must fail.');
        } catch (QueryException $exception) {
            $this->assertStringContainsString('append-only', $exception->getMessage());
        }

        try {
            DB::table('kyc_review_audits')->where('id', $auditId)->delete();
            $this->fail('Deleting an audit entry must fail.');
        } catch (QueryException $exception) {
            $this->assertStringContainsString('append-only', $exception->getMessage());
        }
    }

    private function createUser(): User
    {
        return User::create([
            'name' => 'KYC Test User',
            'phone' => '2547' . random_int(10000000, 99999999),
            'password' => bcrypt('test-password-123'),
            'role' => 'farmer',
            'kyc_status' => 'pending',
        ]);
    }

    private function tokenFor(User $user): string
    {
        $now = time();

        return JWT::encode([
            'iss' => (string) config('app.url'),
            'sub' => (string) $user->id,
            'iat' => $now,
            'nbf' => $now,
            'exp' => $now + 3600,
            'jti' => (string) Str::uuid(),
        ], (string) config('jwt.secret'), 'HS256');
    }
}
