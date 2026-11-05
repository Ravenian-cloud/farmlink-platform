<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\UserProfile;
use Firebase\JWT\JWT;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class ProfileControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_user_can_update_only_their_own_reporting_county(): void
    {
        $user = User::create([
            'name' => 'Profile owner',
            'phone' => '2547' . random_int(10000000, 99999999),
            'password' => bcrypt('profile-test-password'),
            'role' => 'farmer',
        ]);
        $token = JWT::encode([
            'iss' => (string) config('app.url'),
            'sub' => (string) $user->id,
            'iat' => time(),
            'nbf' => time(),
            'exp' => time() + 3600,
            'jti' => (string) Str::uuid(),
        ], (string) config('jwt.secret'), 'HS256');

        $this->withToken($token)->patchJson('/api/v1/profile', ['county' => 'Nairobi'])
            ->assertOk()
            ->assertJsonPath('data.county', 'nairobi');

        $this->assertDatabaseHas('user_profiles', [
            'user_id' => $user->id,
            'county' => 'nairobi',
        ]);
    }
}
