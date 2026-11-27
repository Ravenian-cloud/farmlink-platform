<?php

namespace Database\Seeders;

use App\Models\Product;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    /**
     * Populate the marketplace with repeatable Kenyan demo listings.
     * Prices are stored in minor units (KES cents), as expected by products.price_minor.
     */
    public function run(): void
    {
        $seller = User::withTrashed()->firstOrCreate(
            ['phone' => '+254700000101'],
            [
                'name' => 'Sylvia Nguhi',
                'email' => 'sylvia.farmer@example.test',
                'password' => Hash::make('password'),
                'role' => 'farmer',
                'kyc_status' => 'verified',
            ],
        );

        if ($seller->trashed()) {
            $seller->restore();
        }

        $listings = [
            [
                'category' => 'produce',
                'name' => 'Maize harvest',
                'description' => 'Dry, sorted maize harvested this season.',
                'crop' => 'Maize',
                'quantity_available' => 500,
                'unit' => 'kg',
                'price_minor' => 4500,
                'county' => 'Nakuru',
            ],
            [
                'category' => 'produce',
                'name' => 'Potatoes',
                'description' => 'Clean Irish potatoes, sold in bulk.',
                'crop' => 'Potatoes',
                'quantity_available' => 300,
                'unit' => 'kg',
                'price_minor' => 3800,
                'county' => 'Nakuru',
            ],
            [
                'category' => 'produce',
                'name' => 'Tomatoes',
                'description' => 'Ripe fresh tomatoes, packed in crates.',
                'crop' => 'Tomatoes',
                'quantity_available' => 120,
                'unit' => 'kg',
                'price_minor' => 6500,
                'county' => 'Kiambu',
            ],
            [
                'category' => 'input',
                'name' => 'DAP fertiliser',
                'description' => '50 kg bag of planting fertiliser.',
                'input_type' => 'Fertiliser',
                'brand' => 'Demo Agri',
                'quantity_available' => 40,
                'unit' => 'bag',
                'price_minor' => 620000,
                'county' => 'Nakuru',
            ],
            [
                'category' => 'input',
                'name' => 'Certified maize seed',
                'description' => '2 kg packet of certified maize seed.',
                'input_type' => 'Seed',
                'brand' => 'Demo Seeds',
                'quantity_available' => 75,
                'unit' => 'packet',
                'price_minor' => 85000,
                'county' => 'Kiambu',
            ],
        ];

        foreach ($listings as $listing) {
            Product::updateOrCreate(
                ['seller_id' => $seller->id, 'name' => $listing['name']],
                $listing + [
                    'seller_id' => $seller->id,
                    'currency' => 'KES',
                    'status' => 'active',
                ],
            );
        }
    }
}
