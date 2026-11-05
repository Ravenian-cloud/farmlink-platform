<?php

namespace App\Http\Controllers;

use App\Models\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ProductController extends Controller
{
    public function mine(Request $request): JsonResponse
    {
        $products = Product::query()
            ->where('seller_id', $request->user()->id)
            ->latest()
            ->paginate(20);

        return response()->json($products);
    }

    public function index(Request $request): JsonResponse
    {
        $products = Product::query()
            ->leftJoin('subscriptions', function ($join): void {
                $join->on('subscriptions.user_id', '=', 'products.seller_id')
                    ->where('subscriptions.status', '=', 'active')
                    ->where('subscriptions.ends_at', '>', now());
            })
            ->select('products.*')
            ->with('seller:id,name,role,kyc_status')
            ->where('products.status', 'active')
            ->when($request->query('category'), fn ($query, $category) => $query->where('category', $category))
            ->when($request->query('search'), fn ($query, $search) => $query->where(function ($builder) use ($search): void {
                $builder->where('name', 'ilike', '%' . addcslashes($search, '%_\\') . '%')
                    ->orWhere('input_type', 'ilike', '%' . addcslashes($search, '%_\\') . '%');
            }))
            ->orderByRaw('CASE WHEN subscriptions.id IS NULL THEN 1 ELSE 0 END')
            ->latest('products.created_at')
            ->paginate(min(max((int) $request->query('per_page', 20), 1), 100));

        return response()->json($products);
    }

    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        $validated = $request->validate([
            'category' => ['required', Rule::in(['produce', 'input'])],
            'name' => ['required', 'string', 'max:180'],
            'description' => ['nullable', 'string', 'max:10000'],
            'crop' => ['required_if:category,produce', 'nullable', 'string', 'max:100'],
            'input_type' => ['required_if:category,input', 'nullable', 'string', 'max:100'],
            'brand' => ['nullable', 'string', 'max:120'],
            'quantity_available' => ['required', 'numeric', 'gt:0', 'max:999999999'],
            'unit' => ['required', 'string', 'max:24'],
            'price_minor' => ['required', 'integer', 'min:1'],
            'currency' => ['required', Rule::in(['KES'])],
            'county' => ['nullable', 'string', 'max:100'],
        ]);

        $allowedRole = $validated['category'] === 'produce' ? 'farmer' : 'supplier';
        abort_unless($user->role === $allowedRole, 403, 'Your account role cannot create this product category.');

        $product = Product::create([
            ...$validated,
            'seller_id' => $user->id,
            'description' => $validated['description'] ?? '',
            'currency' => strtoupper($validated['currency']),
        ]);

        return response()->json(['data' => $product], 201);
    }

    public function show(Product $product): JsonResponse
    {
        abort_unless($product->status === 'active', 404);
        $product->load('seller:id,name,role,kyc_status');

        return response()->json(['data' => $product]);
    }
}
