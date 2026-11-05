<?php

namespace Tests\Feature;

use App\Models\Bid;
use App\Models\BidRevision;
use App\Models\Product;
use App\Models\Transaction;
use App\Models\User;
use Firebase\JWT\JWT;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class DashboardDataAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_my_products_returns_only_the_authenticated_sellers_listings(): void
    {
        $seller = $this->user('farmer');
        $otherSeller = $this->user('supplier');
        Product::create($this->productAttributes($seller));
        Product::create($this->productAttributes($otherSeller, 'input'));

        $this->withToken($this->token($seller))
            ->getJson('/api/v1/my/products')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.seller_id', $seller->id);
    }

    public function test_transactions_returns_only_records_where_user_is_a_participant(): void
    {
        $buyer = $this->user('buyer');
        $seller = $this->user('farmer');
        $otherUser = $this->user('buyer');
        $product = Product::create($this->productAttributes($seller));
        $bid = Bid::create([
            'product_id' => $product->id,
            'buyer_id' => $buyer->id,
            'seller_id' => $seller->id,
            'status' => 'accepted',
        ]);
        Transaction::create([
            'bid_id' => $bid->id,
            'product_id' => $product->id,
            'buyer_id' => $buyer->id,
            'seller_id' => $seller->id,
            'quantity' => 1,
            'unit' => 'kg',
            'unit_price_minor' => 100,
            'total_minor' => 100,
            'currency' => 'KES',
            'status' => 'awaiting_payment',
            'payment_status' => 'initiated',
            'escrow_status' => 'not_funded',
        ]);

        $this->withToken($this->token($buyer))
            ->getJson('/api/v1/transactions')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.buyer_id', $buyer->id)
            ->assertJsonMissingPath('data.0.checkout_request_id')
            ->assertJsonMissingPath('data.0.mpesa_receipt');
        $this->withToken($this->token($otherUser))
            ->getJson('/api/v1/transactions')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_a_bid_participant_cannot_counter_their_own_latest_offer(): void
    {
        $buyer = $this->user('buyer');
        $buyer->update(['kyc_status' => 'verified']);
        $seller = $this->user('farmer');
        $product = Product::create($this->productAttributes($seller));
        $bid = Bid::create([
            'product_id' => $product->id,
            'buyer_id' => $buyer->id,
            'seller_id' => $seller->id,
            'status' => 'pending',
            'expires_at' => now()->addDay(),
        ]);
        BidRevision::create([
            'bid_id' => $bid->id,
            'created_by' => $buyer->id,
            'revision_number' => 1,
            'quantity' => 1,
            'unit_price_minor' => 100,
            'currency' => 'KES',
        ]);

        $this->withToken($this->token($buyer))
            ->postJson("/api/v1/bids/{$bid->id}/counter", [
                'quantity' => 1,
                'unit_price_minor' => 100,
            ], ['Idempotency-Key' => (string) Str::uuid()])
                ->assertStatus(409);
    }

    private function productAttributes(User $seller, string $category = 'produce'): array
    {
        return [
            'seller_id' => $seller->id,
            'category' => $category,
            'name' => 'Farm produce ' . Str::uuid(),
            'description' => '',
            'crop' => $category === 'produce' ? 'maize' : null,
            'input_type' => $category === 'input' ? 'seed' : null,
            'quantity_available' => 10,
            'unit' => 'kg',
            'price_minor' => 100,
            'currency' => 'KES',
            'status' => 'active',
        ];
    }

    private function user(string $role): User
    {
        return User::create([
            'name' => 'Dashboard user',
            'phone' => '2547' . random_int(10000000, 99999999),
            'password' => bcrypt('dashboard-test-password'),
            'role' => $role,
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
