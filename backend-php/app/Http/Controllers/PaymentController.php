<?php

namespace App\Http\Controllers;

use App\Models\Transaction;
use App\Services\PaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PaymentController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $transactions = Transaction::query()
            ->with(['product:id,name,category', 'buyer:id,name', 'seller:id,name'])
            ->select([
                'id',
                'product_id',
                'buyer_id',
                'seller_id',
                'quantity',
                'unit',
                'unit_price_minor',
                'total_minor',
                'currency',
                'status',
                'payment_status',
                'escrow_status',
                'paid_at',
                'delivered_at',
                'created_at',
            ])
            ->where(fn ($query) => $query
                ->where('buyer_id', $user->id)
                ->orWhere('seller_id', $user->id))
            ->latest()
            ->paginate(20)
            ->through(static fn (Transaction $transaction): array => [
                'id' => $transaction->id,
                'product' => $transaction->product,
                'buyer_id' => $transaction->buyer_id,
                'seller_id' => $transaction->seller_id,
                'buyer' => $transaction->buyer,
                'seller' => $transaction->seller,
                'quantity' => $transaction->quantity,
                'unit' => $transaction->unit,
                'unit_price_minor' => $transaction->unit_price_minor,
                'total_minor' => $transaction->total_minor,
                'currency' => $transaction->currency,
                'status' => $transaction->status,
                'payment_status' => $transaction->payment_status,
                'escrow_status' => $transaction->escrow_status,
                'paid_at' => $transaction->paid_at,
                'delivered_at' => $transaction->delivered_at,
                'created_at' => $transaction->created_at,
            ]);

        return response()->json($transactions);
    }

    public function show(Request $request, Transaction $transaction): JsonResponse
    {
        abort_unless(in_array($request->user()->id, [$transaction->buyer_id, $transaction->seller_id], true), 403);

        return response()->json(['data' => [
            'id' => $transaction->id,
            'status' => $transaction->status,
            'payment_status' => $transaction->payment_status,
            'escrow_status' => $transaction->escrow_status,
            'escrow_mode' => 'simulated',
            'amount_minor' => $transaction->total_minor,
            'currency' => $transaction->currency,
            'paid_at' => $transaction->paid_at,
        ]]);
    }

    public function initiate(Request $request, Transaction $transaction, PaymentService $payments): JsonResponse
    {
        $validated = $request->validate(['phone' => ['required', 'string', 'max:32']]);

        return response()->json([
            'data' => $payments->initiateStkPush($transaction, $request->user(), $validated['phone']),
        ], 202);
    }

    public function callback(Request $request, PaymentService $payments): JsonResponse
    {
        return response()->json($payments->handleCallback($request->all()));
    }

    public function confirmDelivery(Request $request, Transaction $transaction, PaymentService $payments): JsonResponse
    {
        $updated = $payments->confirmDeliveryAndRelease($transaction, $request->user());

        return response()->json(['data' => [
            'id' => $updated->id,
            'status' => $updated->status,
            'escrow_status' => $updated->escrow_status,
            'escrow_mode' => 'simulated',
            'delivered_at' => $updated->delivered_at,
        ]]);
    }
}
