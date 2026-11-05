<?php

namespace Tests\Feature;

use App\Models\User;
use Firebase\JWT\JWT;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class PilotFeedbackTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_pilot_user_can_submit_negotiation_and_recommendation_feedback(): void
    {
        $user = $this->user();
        $token = $this->token($user);

        $this->withToken($token)->postJson('/api/v1/pilot-feedback', [
            'category' => 'negotiation',
            'rating' => 4,
            'negotiation_rating' => 5,
            'summary' => 'Clear counter-offer flow',
            'details' => 'The bid history helped me understand the latest offer.',
        ], ['Idempotency-Key' => (string) Str::uuid()])
            ->assertCreated()
            ->assertJsonPath('data.category', 'negotiation');

        $this->withToken($token)->postJson('/api/v1/pilot-feedback', [
            'category' => 'recommendation',
            'rating' => 3,
            'recommendation_accuracy_rating' => 2,
            'details' => 'The suggested input did not match the crop stage.',
        ], ['Idempotency-Key' => (string) Str::uuid()])
            ->assertCreated();

        $this->assertDatabaseCount('pilot_feedback', 2);
    }

    public function test_category_specific_rating_is_required_and_invalid_ratings_are_rejected(): void
    {
        $token = $this->token($this->user());

        $this->withToken($token)->postJson('/api/v1/pilot-feedback', [
            'category' => 'recommendation',
            'details' => 'The recommendation feedback is detailed enough.',
        ], ['Idempotency-Key' => (string) Str::uuid()])
            ->assertUnprocessable();

        $this->withToken($token)->postJson('/api/v1/pilot-feedback', [
            'category' => 'bug',
            'rating' => 6,
            'details' => 'This rating exceeds the allowed scale.',
        ], ['Idempotency-Key' => (string) Str::uuid()])
            ->assertUnprocessable();

        $this->assertDatabaseCount('pilot_feedback', 0);
    }

    public function test_feedback_summary_requires_an_allowlisted_pilot_admin(): void
    {
        $admin = $this->user();
        $other = $this->user();
        config(['services.pilot.feedback_admin_ids' => $admin->id]);

        $this->withToken($this->token($other))
            ->getJson('/api/v1/pilot-feedback/summary')
            ->assertForbidden();

        $this->withToken($this->token($admin))
            ->getJson('/api/v1/pilot-feedback/summary')
            ->assertOk()
            ->assertJsonPath('data', []);
    }

    private function user(): User
    {
        return User::create([
            'name' => 'Pilot participant',
            'phone' => '2547' . random_int(10000000, 99999999),
            'password' => bcrypt('pilot-test-password'),
            'role' => 'buyer',
        ]);
    }

    private function token(User $user): string
    {
        return JWT::encode([
            'iss' => (string) config('app.url'),
            'sub' => (string) $user->id,
            'iat' => time(),
            'nbf' => time(),
            'exp' => time() + 3600,
            'jti' => (string) Str::uuid(),
        ], (string) config('jwt.secret'), 'HS256');
    }
}
