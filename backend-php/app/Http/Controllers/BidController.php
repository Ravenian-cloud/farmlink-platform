<?php

namespace App\Http\Controllers;

use App\Models\Bid;
use App\Models\BidRevision;
use App\Models\Product;
use App\Models\Transaction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class BidController extends Controller
{
    public function store(Request $request, Product $product): JsonResponse
    {
        $validated = $request->validate([
            'quantity' => ['required', 'numeric', 'gt:0', 'decimal:0,3'],
            'unit_price_minor' => ['required', 'integer', 'min:1'],
            'message' => ['nullable', 'string', 'max:1000'],
        ]);
        $buyer = $request->user();
        abort_unless($buyer->role === 'buyer', 403, 'Only buyer accounts can submit a bid.');

        if ($product->status !== 'active' || $product->seller_id === $buyer->id) {
            abort(409, 'This product is not available for bidding.');
        }
        if ($this->quantityMilli((string) $validated['quantity']) > $this->quantityMilli((string) $product->quantity_available)) {
            throw ValidationException::withMessages(['quantity' => 'Quantity exceeds current availability.']);
        }

        $bid = DB::transaction(function () use ($product, $buyer, $validated): Bid {
            $bid = Bid::create([
                'product_id' => $product->id,
                'buyer_id' => $buyer->id,
                'seller_id' => $product->seller_id,
                'status' => 'pending',
                'expires_at' => now()->addDays(3),
            ]);
            $bid->revisions()->create([
                'created_by' => $buyer->id,
                'revision_number' => 1,
                'quantity' => $validated['quantity'],
                'unit_price_minor' => $validated['unit_price_minor'],
                'currency' => $product->currency,
                'message' => $validated['message'] ?? null,
            ]);

            return $bid;
        });

        return response()->json(['data' => $this->present($bid)], 201);
    }

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $bids = Bid::query()
            ->with(['revisions', 'product:id,name,category'])
            ->where(fn ($query) => $query->where('buyer_id', $user->id)->orWhere('seller_id', $user->id))
            ->latest()
            ->paginate(20);

        return response()->json($bids);
    }

    public function counter(Request $request, Bid $bid): JsonResponse
    {
        $validated = $request->validate([
            'quantity' => ['required', 'numeric', 'gt:0', 'decimal:0,3'],
            'unit_price_minor' => ['required', 'integer', 'min:1'],
            'message' => ['nullable', 'string', 'max:1000'],
        ]);

        $updated = DB::transaction(function () use ($request, $bid, $validated): Bid {
            $locked = Bid::query()->lockForUpdate()->findOrFail($bid->id);
            abort_unless(in_array($request->user()->id, [$locked->buyer_id, $locked->seller_id], true), 403);
            abort_unless($locked->status === 'pending' && $locked->expires_at?->isFuture(), 409, 'This bid is no longer open.');
            $latestRevision = $locked->revisions()->orderByDesc('revision_number')->firstOrFail();
            abort_unless(
                (string) $latestRevision->created_by !== (string) $request->user()->id,
                409,
                'Wait for the other party to respond before sending another counter-offer.',
            );
            $product = Product::query()->findOrFail($locked->product_id);
            if ($this->quantityMilli((string) $validated['quantity']) > $this->quantityMilli((string) $product->quantity_available)) {
                throw ValidationException::withMessages(['quantity' => 'Quantity exceeds current availability.']);
            }

            $locked->revisions()->create([
                'created_by' => $request->user()->id,
                'revision_number' => ((int) $latestRevision->revision_number) + 1,
                'quantity' => $validated['quantity'],
                'unit_price_minor' => $validated['unit_price_minor'],
                'currency' => $product->currency,
                'message' => $validated['message'] ?? null,
            ]);

            return $locked->load('revisions');
        });

        return response()->json(['data' => $this->present($updated)]);
    }

    public function accept(Request $request, Bid $bid): JsonResponse
    {
        [$acceptedBid, $transaction] = DB::transaction(function () use ($request, $bid): array {
            $locked = Bid::query()->lockForUpdate()->findOrFail($bid->id);
            abort_unless($request->user()->id === $locked->seller_id, 403, 'Only the seller can accept this bid.');
            abort_unless($locked->status === 'pending' && $locked->expires_at?->isFuture(), 409, 'This bid is no longer open.');
            $product = Product::query()->lockForUpdate()->findOrFail($locked->product_id);
            abort_unless($product->status === 'active', 409, 'This product is no longer available.');
            $revision = $locked->revisions()->orderByDesc('revision_number')->firstOrFail();

            $availableMilli = $this->quantityMilli((string) $product->quantity_available);
            $acceptedMilli = $this->quantityMilli((string) $revision->quantity);
            if ($acceptedMilli > $availableMilli) {
                throw ValidationException::withMessages(['quantity' => 'Accepted quantity exceeds current availability.']);
            }
            $totalMinor = $this->calculateTotalMinor((string) $revision->quantity, (int) $revision->unit_price_minor);
            if ($revision->currency !== 'KES' || $totalMinor < 100 || $totalMinor % 100 !== 0) {
                throw ValidationException::withMessages([
                    'quantity' => 'The total must be a positive whole-KES amount for M-Pesa checkout.',
                ]);
            }

            $locked->update(['status' => 'accepted']);
            $remainingMilli = $availableMilli - $acceptedMilli;
            $product->update([
                'quantity_available' => intdiv($remainingMilli, 1000) . '.' . str_pad((string) ($remainingMilli % 1000), 3, '0', STR_PAD_LEFT),
                'status' => $remainingMilli === 0 ? 'sold_out' : $product->status,
            ]);

            $transaction = Transaction::create([
                'bid_id' => $locked->id,
                'product_id' => $product->id,
                'buyer_id' => $locked->buyer_id,
                'seller_id' => $locked->seller_id,
                'quantity' => $revision->quantity,
                'unit' => $product->unit,
                'unit_price_minor' => $revision->unit_price_minor,
                'total_minor' => $totalMinor,
                'currency' => $revision->currency,
                'status' => 'awaiting_payment',
                'payment_status' => 'initiated',
                'escrow_status' => 'not_funded',
            ]);

            return [$locked->load('revisions'), $transaction];
        });

        return response()->json([
            'data' => ['bid' => $this->present($acceptedBid), 'transaction' => $transaction],
        ]);
    }

    public function reject(Request $request, Bid $bid): JsonResponse
    {
        DB::transaction(function () use ($request, $bid): void {
            $locked = Bid::query()->lockForUpdate()->findOrFail($bid->id);
            abort_unless($request->user()->id === $locked->seller_id, 403, 'Only the seller can reject this bid.');
            abort_unless($locked->status === 'pending', 409, 'This bid is no longer open.');
            $locked->update(['status' => 'rejected']);
        });

        return response()->json(['data' => ['id' => $bid->id, 'status' => 'rejected']]);
    }

    private function calculateTotalMinor(string $quantity, int $unitPriceMinor): int
    {
        $quantityMilli = $this->quantityMilli($quantity);
        if ($quantityMilli < 1 || ($unitPriceMinor !== 0 && $quantityMilli > intdiv(PHP_INT_MAX, $unitPriceMinor))) {
            throw ValidationException::withMessages(['quantity' => 'The negotiated total is outside the supported range.']);
        }

        $scaledTotal = $quantityMilli * $unitPriceMinor;
        if ($scaledTotal % 1000 !== 0) {
            throw ValidationException::withMessages(['quantity' => 'This quantity and unit price cannot be represented in minor currency units.']);
        }

        return intdiv($scaledTotal, 1000);
    }

    private function quantityMilli(string $quantity): int
    {
        if (!preg_match('/^(\d+)(?:\.(\d{1,3}))?$/', $quantity, $matches)) {
            throw ValidationException::withMessages(['quantity' => 'Quantity must have no more than three decimal places.']);
        }

        return ((int) $matches[1] * 1000) + (int) str_pad($matches[2] ?? '', 3, '0');
    }

    private function present(Bid $bid): array
    {
        $bid->loadMissing('revisions');
        return [
            'id' => $bid->id,
            'product_id' => $bid->product_id,
            'buyer_id' => $bid->buyer_id,
            'seller_id' => $bid->seller_id,
            'status' => $bid->status,
            'current_offer' => $bid->revisions->last(),
            'history' => $bid->revisions,
            'expires_at' => $bid->expires_at,
        ];
    }
}
