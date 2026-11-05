<?php

namespace Tests\Feature;

use App\Models\User;
use Firebase\JWT\JWT;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class KycEnforcementTest extends TestCase
{
    use RefreshDatabase;

    public function test_unverified_user_cannot_create_a_marketplace_listing(): void
    {
        $user = User::create([
            'name' => 'Pending Farmer',
            'phone' => '2547' . random_int(10000000, 99999999),
            'password' => bcrypt('test-password-123'),
            'role' => 'farmer',
            'kyc_status' => 'pending',
        ]);
        $token = JWT::encode([
            'iss' => (string) config('app.url'),
            'sub' => (string) $user->id,
            'iat' => time(),
            'nbf' => time(),
            'exp' => time() + 3600,
            'jti' => (string) Str::uuid(),
        ], (string) config('jwt.secret'), 'HS256');

        $this->withToken($token)
            ->postJson('/api/v1/products', [
                'category' => 'produce',
                'name' => 'Maize',
                'crop' => 'maize',
                'quantity_available' => 100,
                'unit' => 'kg',
                'price_minor' => 5000,
                'currency' => 'KES',
            ], ['Idempotency-Key' => (string) Str::uuid()])
            ->assertForbidden()
            ->assertJsonPath('message', 'Verified KYC is required for marketplace actions.');

        $this->assertDatabaseCount('products', 0);
    }
}
