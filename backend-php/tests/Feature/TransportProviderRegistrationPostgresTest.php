<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class TransportProviderRegistrationPostgresTest extends TestCase
{
    use RefreshDatabase;

    public function test_transport_provider_can_register_on_postgresql(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('This regression test requires PostgreSQL.');
        }

        $this->postJson('/api/v1/auth/register', [
            'name' => 'Transport Provider',
            'phone' => '254700123456',
            'password' => 'a-secure-test-password',
            'role' => 'transport_provider',
        ])
            ->assertCreated()
            ->assertJsonPath('data.role', 'transport_provider');
    }
}
