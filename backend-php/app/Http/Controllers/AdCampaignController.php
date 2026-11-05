<?php

namespace App\Http\Controllers;

use App\Models\AdCampaign;
use App\Models\MpesaPurchaseIntent;
use App\Models\User;
use App\Services\PaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class AdCampaignController extends Controller
{
    public function pricing(): JsonResponse
    {
        $amount = (int) config('services.monetization.ad_campaign_minor');
        return response()->json(['data' => [
            'monthly_price_minor' => $amount,
            'currency' => 'KES',
            'enabled' => $amount >= 100 && $amount % 100 === 0,
            'duration_days' => 30,
        ]]);
    }

    public function index(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $campaign = AdCampaign::query()
            ->where('status', 'active')
            ->where('target_role', $user->role)
            ->where('starts_at', '<=', now())
            ->where('ends_at', '>', now())
            ->inRandomOrder()
            ->first();

        if (!$campaign) {
            return response()->json(['data' => null]);
        }

        return response()->json(['data' => [
            'id' => $campaign->id,
            'name' => $campaign->name,
            'creative_url' => route('ads.creative', ['campaign' => $campaign->id]),
            'destination_url' => $campaign->destination_url,
            'label' => 'Sponsored',
        ]]);
    }

    public function creative(AdCampaign $campaign)
    {
        abort_unless($campaign->status === 'active' && $campaign->starts_at?->isPast() && $campaign->ends_at?->isFuture(), 404);
        abort_unless(Storage::disk('local')->exists($campaign->creative_path), 404);

        return Storage::disk('local')->response($campaign->creative_path, null, [
            'Cache-Control' => 'public, max-age=300',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        abort_unless($user->role === 'supplier' && $user->kyc_status === 'verified', 403, 'A verified supplier account is required to advertise.');
        $amount = (int) config('services.monetization.ad_campaign_minor');
        abort_if($amount < 100 || $amount % 100 !== 0, 503, 'Advertising pricing is not configured in whole Kenyan shillings.');
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'target_role' => ['required', Rule::in(['farmer', 'buyer', 'supplier'])],
            'destination_url' => ['required', 'url', 'max:2048', 'regex:/^https:\/\/.+/i'],
            'creative' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
        ]);
        $path = $validated['creative']->store('ad-creatives', 'local');
        if (!is_string($path) || $path === '') {
            throw new \RuntimeException('The advertisement creative could not be stored.');
        }

        try {
            $campaign = AdCampaign::create([
                'advertiser_id' => $user->id,
                'name' => $validated['name'],
                'target_role' => $validated['target_role'],
                'destination_url' => $validated['destination_url'],
                'creative_path' => $path,
                'status' => 'draft',
            ]);
        } catch (\Throwable $exception) {
            Storage::disk('local')->delete($path);
            throw $exception;
        }

        return response()->json([
            'data' => [
                'id' => $campaign->id,
                'name' => $campaign->name,
                'target_role' => $campaign->target_role,
                'status' => $campaign->status,
            ],
            'next_step' => 'pay',
        ], 201);
    }

    public function mine(Request $request): JsonResponse
    {
        return response()->json(['data' => AdCampaign::query()
            ->where('advertiser_id', $request->user()->id)
            ->selectSub(
                DB::table('mpesa_purchase_intents')
                    ->select('status')
                    ->whereColumn('mpesa_purchase_intents.ad_campaign_id', 'ad_campaigns.id')
                    ->latest()
                    ->limit(1),
                'payment_status',
            )
            ->latest()
            ->limit(100)
            ->get(['id', 'name', 'target_role', 'status', 'starts_at', 'ends_at', 'impressions_count', 'clicks_count', 'created_at'])]);
    }

    public function purchase(Request $request, AdCampaign $campaign, PaymentService $payments): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        abort_unless($campaign->advertiser_id === $user->id, 403);
        abort_unless($campaign->status === 'draft', 409, 'Only draft advertisements can be purchased.');
        $amount = (int) config('services.monetization.ad_campaign_minor');
        abort_if($amount < 100 || $amount % 100 !== 0, 503, 'Advertising pricing is not configured in whole Kenyan shillings.');
        $validated = $request->validate(['phone' => ['required', 'string', 'max:32']]);
        $intent = DB::transaction(function () use ($campaign, $user, $amount): MpesaPurchaseIntent {
            $locked = AdCampaign::query()->lockForUpdate()->findOrFail($campaign->id);
            abort_unless($locked->advertiser_id === $user->id && $locked->status === 'draft', 409, 'This campaign cannot be purchased.');
            abort_if(
                MpesaPurchaseIntent::query()->where('ad_campaign_id', $locked->id)->whereIn('status', ['initiated', 'pending'])->exists(),
                409,
                'A campaign payment is already pending.',
            );

            return MpesaPurchaseIntent::create([
                'ad_campaign_id' => $locked->id,
                'user_id' => $user->id,
                'amount_minor' => $amount,
                'currency' => 'KES',
                'status' => 'initiated',
            ]);
        });

        return response()->json(['data' => $payments->initiatePurchaseIntent($intent, $user, $validated['phone'])], 202);
    }

    public function recordEvent(Request $request, AdCampaign $campaign, string $event): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        abort_unless($event === 'impression' || $event === 'click', 404);
        abort_unless($campaign->status === 'active'
            && $campaign->target_role === $user->role
            && $campaign->starts_at?->isPast()
            && $campaign->ends_at?->isFuture(), 404);

        $visitorHash = hash_hmac('sha256', $campaign->id . ':' . $user->id, (string) config('app.key'));
        DB::transaction(function () use ($campaign, $event, $visitorHash): void {
            $inserted = DB::table('ad_events')->insertOrIgnore([
                'campaign_id' => $campaign->id,
                'event_type' => $event,
                'visitor_hash' => $visitorHash,
                'event_date' => now()->toDateString(),
                'created_at' => now(),
            ]);
            if ($inserted === 0) {
                return;
            }
            AdCampaign::query()->whereKey($campaign->id)
                ->increment($event === 'impression' ? 'impressions_count' : 'clicks_count');
        });

        return response()->json(['data' => ['recorded' => true]]);
    }

    public function review(Request $request, AdCampaign $campaign): JsonResponse
    {
        $validated = $request->validate(['decision' => ['required', Rule::in(['approve', 'reject'])]]);
        abort_unless($campaign->status === 'pending_review', 409, 'Only paid advertisements awaiting review can be moderated.');

        $campaign = DB::transaction(function () use ($campaign, $validated): AdCampaign {
            $locked = AdCampaign::query()->lockForUpdate()->findOrFail($campaign->id);
            abort_unless($locked->status === 'pending_review', 409, 'Only paid advertisements awaiting review can be moderated.');
            if ($validated['decision'] === 'approve') {
                $locked->forceFill([
                    'status' => 'active',
                    'starts_at' => now(),
                    'ends_at' => now()->addDays(30),
                ])->save();
            } else {
                $locked->forceFill(['status' => 'rejected'])->save();
            }

            return $locked;
        });

        return response()->json(['data' => $campaign]);
    }
}
