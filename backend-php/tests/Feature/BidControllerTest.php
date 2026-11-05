<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\User;
use Firebase\JWT\JWT;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BidControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_buyer_can_submit_and_seller_can_accept_a_bid_at_a_frozen_price(): void
    {
        $seller = $this->user('farmer');
        $buyer = $this->user('buyer');
        $product = $this->product($seller, '10.000');

        $this->withToken($this->token($buyer))
            ->postJson("/api/v1/products/{$product->id}/bids", [
                'quantity' => 2,
                'unit_price_minor' => 5000,
            ], ['Idempotency-Key' => $this->uuid()])
            ->assertCreated()
            ->assertJsonPath('data.status', 'pending');

        $bidId = \App\Models\Bid::query()->firstOrFail()->id;
        $this->withToken($this->token($seller))
            ->postJson("/api/v1/bids/{$bidId}/accept", [], ['Idempotency-Key' => $this->uuid()])
            ->assertOk()
            ->assertJsonPath('data.bid.status', 'accepted')
            ->assertJsonPath('data.transaction.total_minor', 10000);

        $this->assertDatabaseHas('bids', ['id' => $bidId, 'status' => 'accepted']);
        $this->assertDatabaseHas('transactions', [
            'bid_id' => $bidId,
            'unit_price_minor' => 5000,
            'total_minor' => 10000,
            'status' => 'awaiting_payment',
        ]);
        $this->assertDatabaseHas('products', ['id' => $product->id, 'quantity_available' => 8]);
    }

    public function test_seller_can_reject_a_bid_and_rejected_bid_cannot_be_accepted(): void
    {
        $seller = $this->user('supplier');
        $buyer = $this->user('buyer');
        $product = $this->product($seller, '10.000', 'input');
        $bid = $this->createBid($product, $buyer, $seller);

        $this->withToken($this->token($seller))
            ->postJson("/api/v1/bids/{$bid->id}/reject", [], ['Idempotency-Key' => $this->uuid()])
            ->assertOk()
            ->assertJsonPath('data.status', 'rejected');

        $this->withToken($this->token($seller))
            ->postJson("/api/v1/bids/{$bid->id}/accept", [], ['Idempotency-Key' => $this->uuid()])
            ->assertStatus(409);

        $this->assertDatabaseMissing('transactions', ['bid_id' => $bid->id]);
    }

    public function test_only_seller_can_accept_and_concurrent_duplicate_acceptance_is_rejected(): void
    {
        $seller = $this->user('farmer');
        $buyer = $this->user('buyer');
        $product = $this->product($seller, '10.000');
        $bid = $this->createBid($product, $buyer, $seller);

        $this->withToken($this->token($buyer))
            ->postJson("/api/v1/bids/{$bid->id}/accept", [], ['Idempotency-Key' => $this->uuid()])
            ->assertForbidden();

        $this->withToken($this->token($seller))
            ->postJson("/api/v1/bids/{$bid->id}/accept", [], ['Idempotency-Key' => $this->uuid()])
            ->assertOk();
        $this->withToken($this->token($seller))
            ->postJson("/api/v1/bids/{$bid->id}/accept", [], ['Idempotency-Key' => $this->uuid()])
            ->assertStatus(409);

        $this->assertDatabaseCount('transactions', 1);
    }

    public function test_acceptance_freezes_the_latest_counteroffer_revision(): void
    {
        $seller = $this->user('farmer');
        $buyer = $this->user('buyer');
        $product = $this->product($seller, '10.000');
        $bid = $this->createBid($product, $buyer, $seller);

        $this->withToken($this->token($seller))
            ->postJson("/api/v1/bids/{$bid->id}/counter", [
                'quantity' => 3,
                'unit_price_minor' => 6000,
            ], ['Idempotency-Key' => $this->uuid()])
            ->assertOk()
            ->assertJsonPath('data.current_offer.revision_number', 2);

        $this->withToken($this->token($seller))
            ->postJson("/api/v1/bids/{$bid->id}/accept", [], ['Idempotency-Key' => $this->uuid()])
            ->assertOk()
            ->assertJsonPath('data.transaction.quantity', '3.000')
            ->assertJsonPath('data.transaction.unit_price_minor', 6000)
            ->assertJsonPath('data.transaction.total_minor', 18000);
    }

    private function user(string $role): User
    {
        return User::create([
            'name' => ucfirst($role) . ' User',
            'phone' => '2547' . random_int(10000000, 99999999),
            'password' => bcrypt('test-password-123'),
            'role' => $role,
            'kyc_status' => 'verified',
        ]);
    }

    private function product(User $seller, string $quantity, string $category = 'produce'): Product
    {
        return Product::create([
            'seller_id' => $seller->id,
            'category' => $category,
            'name' => $category === 'input' ? 'Farm input' : 'Maize',
            'description' => '',
            'crop' => $category === 'produce' ? 'maize' : null,
            'input_type' => $category === 'input' ? 'fertilizer' : null,
            'quantity_available' => $quantity,
            'unit' => 'kg',
            'price_minor' => 5000,
            'currency' => 'KES',
            'status' => 'active',
        ]);
    }

    private function createBid(Product $product, User $buyer, User $seller): \App\Models\Bid
    {
        $bid = \App\Models\Bid::create([
            'product_id' => $product->id,
            'buyer_id' => $buyer->id,
            'seller_id' => $seller->id,
            'status' => 'pending',
            'expires_at' => now()->addDay(),
        ]);
        $bid->revisions()->create([
            'created_by' => $buyer->id,
            'revision_number' => 1,
            'quantity' => 2,
            'unit_price_minor' => 5000,
            'currency' => 'KES',
        ]);

        return $bid;
    }

    private function token(User $user): string
    {
        return JWT::encode([
            'iss' => (string) config('app.url'),
            'sub' => (string) $user->id,
            'iat' => time(),
            'nbf' => time(),
            'exp' => time() + 3600,
            'jti' => $this->uuid(),
        ], (string) config('jwt.secret'), 'HS256');
    }

    private function uuid(): string
    {
        return (string) \Illuminate\Support\Str::uuid();
    }
}
