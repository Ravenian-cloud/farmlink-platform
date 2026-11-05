<?php

namespace App\Http\Controllers;

use App\Models\Delivery;
use App\Models\Transaction;
use App\Models\TransportService;
use App\Models\User;
use App\Services\LocationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class LogisticsController extends Controller
{
    public function openDeliveries(Request $request): JsonResponse
    {
        /** @var User $provider */
        $provider = $request->user();
        abort_unless($provider->role === 'transport_provider', 403, 'A transport provider account is required.');
        $service = TransportService::query()->where('provider_id', $provider->id)->where('available', true)->first();
        abort_unless($service, 409, 'Register an available transport service first.');

        $deliveries = Delivery::query()
            ->where('status', 'requested')
            ->where(function ($query) use ($service): void {
                $query->whereRaw('LOWER(pickup_county) = ?', [mb_strtolower($service->county)])
                    ->orWhereRaw('LOWER(delivery_county) = ?', [mb_strtolower($service->county)]);
            })
            ->with('transaction.product:id,name,quantity_available,unit')
            ->latest()
            ->limit(100)
            ->get()
            ->map(static fn (Delivery $delivery): array => [
                'id' => $delivery->id,
                'pickup_county' => $delivery->pickup_county,
                'delivery_county' => $delivery->delivery_county,
                'product_name' => $delivery->transaction->product->name,
                'unit' => $delivery->transaction->unit,
                'quantity' => $delivery->transaction->quantity,
                'status' => $delivery->status,
            ]);

        return response()->json(['data' => $deliveries]);
    }

    public function registerService(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        abort_unless($user->role === 'transport_provider', 403, 'A transport provider account is required.');
        $validated = $request->validate([
            'county' => ['required', 'string', 'max:100'],
            'service_area' => ['nullable', 'string', 'max:160'],
            'vehicle_type' => ['required', 'string', 'max:80'],
            'capacity_kg' => ['nullable', 'numeric', 'gt:0', 'max:1000000'],
            'base_rate_minor' => ['nullable', 'integer', 'min:100'],
            'available' => ['sometimes', 'boolean'],
        ]);
        $service = TransportService::updateOrCreate(
            ['provider_id' => $user->id],
            [...$validated, 'currency' => 'KES'],
        );

        return response()->json(['data' => $service], 200);
    }

    public function providers(Request $request, LocationService $locations): JsonResponse
    {
        $validated = $request->validate(['county' => ['required', 'string', 'max:100']]);
        $providers = $locations->nearbyProviders($validated['county'])
            ->map(static fn (TransportService $service): array => [
                'id' => $service->provider_id,
                'name' => $service->provider->name,
                'county' => $service->county,
                'service_area' => $service->service_area,
                'vehicle_type' => $service->vehicle_type,
                'capacity_kg' => $service->capacity_kg,
                'base_rate_minor' => $service->base_rate_minor,
                'currency' => $service->currency,
            ]);

        return response()->json(['data' => $providers]);
    }

    public function requestDelivery(Request $request, Transaction $transaction, LocationService $locations): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        abort_unless($transaction->buyer_id === $user->id, 403, 'Only the purchasing buyer can request delivery.');
        abort_unless($transaction->status === 'escrow_held', 409, 'Delivery can only be requested for a paid, undisputed order.');

        $transaction->loadMissing(['product', 'buyer.profile']);
        $pickupCounty = trim((string) $transaction->product->county);
        $deliveryCounty = trim((string) $transaction->buyer->profile?->county);
        abort_if($pickupCounty === '' || $deliveryCounty === '', 422, 'Pickup and delivery counties must be set on the listing and buyer profile.');
        $providers = $locations->nearbyProviders($pickupCounty);
        if ($deliveryCounty !== $pickupCounty) {
            $providers = $providers->merge($locations->nearbyProviders($deliveryCounty))->unique('provider_id');
        }
        abort_if($providers->isEmpty(), 409, 'No verified transport providers are available in the pickup or delivery county.');

        $delivery = DB::transaction(function () use ($transaction, $pickupCounty, $deliveryCounty): Delivery {
            $lockedTransaction = Transaction::query()->lockForUpdate()->findOrFail($transaction->id);
            abort_unless($lockedTransaction->status === 'escrow_held', 409, 'Delivery can only be requested for a paid, undisputed order.');

            return Delivery::firstOrCreate(
                ['transaction_id' => $lockedTransaction->id],
                [
                    'status' => 'requested',
                    'pickup_county' => $pickupCounty,
                    'delivery_county' => $deliveryCounty,
                ],
            );
        });

        return response()->json([
            'data' => $delivery,
            'nearby_provider_count' => $providers->count(),
            'matching_method' => 'county_level_not_gps',
        ], $delivery->wasRecentlyCreated ? 201 : 200);
    }

    public function acceptDelivery(Request $request, Delivery $delivery): JsonResponse
    {
        /** @var User $provider */
        $provider = $request->user();
        abort_unless($provider->role === 'transport_provider', 403, 'A transport provider account is required.');

        $updated = DB::transaction(function () use ($delivery, $provider): Delivery {
            $locked = Delivery::query()->lockForUpdate()->findOrFail($delivery->id);
            abort_unless($locked->status === 'requested' && $locked->provider_id === null, 409, 'This delivery request is no longer available.');
            $service = TransportService::query()->where('provider_id', $provider->id)->where('available', true)->first();
            abort_unless($service && in_array(mb_strtolower($service->county), [
                mb_strtolower($locked->pickup_county),
                mb_strtolower($locked->delivery_county),
            ], true), 403, 'Your transport service is not available in either delivery county.');

            $locked->forceFill(['provider_id' => $provider->id, 'status' => 'assigned'])->save();
            return $locked;
        });

        return response()->json(['data' => $updated]);
    }

    public function showDelivery(Request $request, Delivery $delivery): JsonResponse
    {
        $delivery->loadMissing(['transaction', 'provider:id,name']);
        $transaction = $delivery->transaction;
        abort_unless(in_array($request->user()->id, [
            $transaction->buyer_id,
            $transaction->seller_id,
            $delivery->provider_id,
        ], true), 403);

        return response()->json(['data' => [
            'id' => $delivery->id,
            'transaction_id' => $delivery->transaction_id,
            'status' => $delivery->status,
            'pickup_county' => $delivery->pickup_county,
            'delivery_county' => $delivery->delivery_county,
            'provider' => $delivery->provider ? ['name' => $delivery->provider->name] : null,
            'estimated_pickup_at' => $delivery->estimated_pickup_at,
            'estimated_delivery_at' => $delivery->estimated_delivery_at,
            'picked_up_at' => $delivery->picked_up_at,
            'delivered_at' => $delivery->delivered_at,
        ]]);
    }

    public function updateStatus(Request $request, Delivery $delivery): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['required', Rule::in(['picked_up', 'in_transit', 'delivered'])],
        ]);
        /** @var User $provider */
        $provider = $request->user();

        $updated = DB::transaction(function () use ($delivery, $provider, $validated): Delivery {
            $locked = Delivery::query()->lockForUpdate()->findOrFail($delivery->id);
            abort_unless($locked->provider_id === $provider->id, 403, 'Only the assigned provider can update this delivery.');
            $transitions = [
                'assigned' => 'picked_up',
                'picked_up' => 'in_transit',
                'in_transit' => 'delivered',
            ];
            abort_unless(($transitions[$locked->status] ?? null) === $validated['status'], 409, 'Invalid delivery status transition.');
            $fields = ['status' => $validated['status']];
            if ($validated['status'] === 'picked_up') {
                $fields['picked_up_at'] = now();
            } elseif ($validated['status'] === 'delivered') {
                $fields['delivered_at'] = now();
            }
            $locked->forceFill($fields)->save();

            return $locked;
        });

        return response()->json(['data' => $updated]);
    }
}
