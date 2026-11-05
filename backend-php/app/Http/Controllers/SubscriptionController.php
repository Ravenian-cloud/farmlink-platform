<?php

namespace App\Http\Controllers;

use App\Models\MpesaPurchaseIntent;
use App\Models\Subscription;
use App\Models\User;
use App\Services\PaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SubscriptionController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $subscription = $request->user()->subscription;
        $latestPayment = $subscription?->payments()->latest()->first();

        return response()->json(['data' => [
            'plan' => $subscription?->plan,
            'status' => $subscription?->isActive() ? 'active' : ($subscription?->status ?? 'inactive'),
            'starts_at' => $subscription?->starts_at,
            'ends_at' => $subscription?->ends_at,
            'auto_renew' => false,
            'price_minor' => (int) config('services.monetization.premium_monthly_minor'),
            'currency' => 'KES',
            'latest_payment_status' => $latestPayment?->status,
        ]]);
    }

    public function purchase(Request $request, PaymentService $payments): JsonResponse
    {
        $amount = (int) config('services.monetization.premium_monthly_minor');
        abort_if($amount < 100 || $amount % 100 !== 0, 503, 'Premium pricing is not configured in whole Kenyan shillings.');
        $validated = $request->validate(['phone' => ['required', 'string', 'max:32']]);
        /** @var User $user */
        $user = $request->user();

        $intent = DB::transaction(function () use ($user, $amount): MpesaPurchaseIntent {
            $subscription = Subscription::query()->firstOrCreate(
                ['user_id' => $user->id],
                ['plan' => 'premium_monthly', 'status' => 'inactive'],
            );
            $subscription = Subscription::query()->lockForUpdate()->findOrFail($subscription->id);
            abort_if($subscription->payments()->whereIn('status', ['initiated', 'pending'])->exists(), 409, 'A Premium payment is already pending or being verified.');

            return MpesaPurchaseIntent::create([
                'subscription_id' => $subscription->id,
                'user_id' => $user->id,
                'amount_minor' => $amount,
                'currency' => 'KES',
                'status' => 'initiated',
            ]);
        });

        return response()->json(['data' => $payments->initiatePurchaseIntent($intent, $user, $validated['phone'])], 202);
    }
}
