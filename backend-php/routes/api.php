<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\AnalyticsController;
use App\Http\Controllers\AdCampaignController;
use App\Http\Controllers\BidController;
use App\Http\Controllers\CommunityController;
use App\Http\Controllers\FarmerImpactController;
use App\Http\Controllers\ImpactReportController;
use App\Http\Controllers\LogisticsController;
use App\Http\Controllers\KycReviewController;
use App\Http\Controllers\PaymentController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\PolicyInsightsController;
use App\Http\Controllers\PilotFeedbackController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\SubscriptionController;
use App\Http\Controllers\WeatherWebhookController;
use App\Http\Middleware\AuthenticatePolicyPartner;
use App\Http\Middleware\AuthenticateJwt;
use App\Http\Middleware\IdempotencyGuard;
use App\Http\Middleware\RequireAdAdministrator;
use App\Http\Middleware\RequireCommunityAdministrator;
use App\Http\Middleware\RequirePremium;
use App\Http\Middleware\RequireKycAdministrator;
use App\Http\Middleware\RequireVerifiedKyc;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function (): void {
    Route::get('/health', static fn () => response()->json(['status' => 'ok']));
    Route::post('/auth/register', [AuthController::class, 'register'])->middleware('throttle:10,1');
    Route::post('/auth/login', [AuthController::class, 'login'])->middleware('throttle:10,1');
    Route::post('/webhooks/mpesa', [PaymentController::class, 'callback'])->middleware('throttle:120,1');
    Route::post('/webhooks/weather', WeatherWebhookController::class)->middleware('throttle:60,1');
    Route::get('/ads/{campaign}/creative', [AdCampaignController::class, 'creative'])->name('ads.creative');
    Route::get('/policy/insights', [PolicyInsightsController::class, 'index'])
        ->middleware([AuthenticatePolicyPartner::class, 'throttle:30,1']);
    Route::get('/policy/impact', [ImpactReportController::class, 'index'])
        ->middleware([AuthenticatePolicyPartner::class, 'throttle:30,1']);
    Route::get('/policy/impact/report', [ImpactReportController::class, 'index'])
        ->middleware([AuthenticatePolicyPartner::class, 'throttle:30,1']);

    Route::get('/knowledge/articles', [CommunityController::class, 'articles'])->middleware('throttle:60,1');
    Route::get('/knowledge/articles/{slug}', [CommunityController::class, 'article'])->middleware('throttle:60,1');
    Route::get('/forum/threads', [CommunityController::class, 'threads'])->middleware('throttle:60,1');
    Route::get('/forum/threads/{thread}', [CommunityController::class, 'thread'])->middleware('throttle:60,1');
    Route::get('/products', [ProductController::class, 'index']);
    Route::get('/products/{product}', [ProductController::class, 'show']);
    Route::get('/analytics/market', [AnalyticsController::class, 'index'])->middleware('throttle:60,1');

    Route::middleware(AuthenticateJwt::class)->group(function (): void {
        Route::get('/me', [AuthController::class, 'me']);
        Route::patch('/profile', [ProfileController::class, 'update']);
        Route::get('/my/products', [ProductController::class, 'mine']);
        Route::get('/transactions', [PaymentController::class, 'index']);
        Route::patch('/admin/kyc/users/{userId}/review', [KycReviewController::class, 'review'])
            ->middleware([RequireKycAdministrator::class, 'throttle:30,1']);
        Route::get('/community/admin-access', [CommunityController::class, 'adminAccess']);
        Route::get('/admin/community/articles', [CommunityController::class, 'adminArticles'])
            ->middleware(RequireCommunityAdministrator::class);
        Route::post('/admin/community/articles', [CommunityController::class, 'storeArticle'])
            ->middleware(RequireCommunityAdministrator::class);
        Route::patch('/admin/community/articles/{article}', [CommunityController::class, 'updateArticle'])
            ->middleware(RequireCommunityAdministrator::class);
        Route::get('/admin/community/forum', [CommunityController::class, 'adminThreads'])
            ->middleware(RequireCommunityAdministrator::class);
        Route::get('/admin/community/forum/posts', [CommunityController::class, 'adminPosts'])
            ->middleware(RequireCommunityAdministrator::class);
        Route::patch('/admin/community/forum/threads/{thread}', [CommunityController::class, 'moderateThread'])
            ->middleware(RequireCommunityAdministrator::class);
        Route::patch('/admin/community/forum/threads/{thread}/posts/{post}', [CommunityController::class, 'moderatePost'])
            ->middleware(RequireCommunityAdministrator::class);
        Route::post('/forum/threads', [CommunityController::class, 'createThread'])
            ->middleware(['throttle:10,1', IdempotencyGuard::class]);
        Route::post('/forum/threads/{thread}/posts', [CommunityController::class, 'createPost'])
            ->middleware(['throttle:20,1', IdempotencyGuard::class]);
        Route::get('/impact/observations', [FarmerImpactController::class, 'index']);
        Route::post('/impact/observations', [FarmerImpactController::class, 'store'])
            ->middleware(['throttle:10,1', IdempotencyGuard::class]);
        Route::get('/subscription', [SubscriptionController::class, 'show']);
        Route::post('/subscription/purchase', [SubscriptionController::class, 'purchase'])
            ->middleware([RequireVerifiedKyc::class, IdempotencyGuard::class]);
        Route::get('/analytics/market/advanced', [AnalyticsController::class, 'advanced'])
            ->middleware([RequirePremium::class, 'throttle:30,1']);
        Route::get('/ads', [AdCampaignController::class, 'index'])->middleware('throttle:60,1');
        Route::get('/ads/mine', [AdCampaignController::class, 'mine']);
        Route::get('/ads/pricing', [AdCampaignController::class, 'pricing']);
        Route::post('/ads', [AdCampaignController::class, 'store'])
            ->middleware([RequireVerifiedKyc::class, IdempotencyGuard::class]);
        Route::post('/ads/{campaign}/purchase', [AdCampaignController::class, 'purchase'])
            ->middleware([RequireVerifiedKyc::class, IdempotencyGuard::class]);
        Route::post('/ads/{campaign}/events/{event}', [AdCampaignController::class, 'recordEvent'])
            ->middleware([RequireVerifiedKyc::class, 'throttle:120,1']);
        Route::post('/admin/ads/{campaign}/review', [AdCampaignController::class, 'review'])
            ->middleware(RequireAdAdministrator::class);
        Route::post('/transport/service', [LogisticsController::class, 'registerService'])
            ->middleware(RequireVerifiedKyc::class);
        Route::get('/transport/deliveries', [LogisticsController::class, 'openDeliveries'])
            ->middleware([RequireVerifiedKyc::class, 'throttle:60,1']);
        Route::get('/transport/providers', [LogisticsController::class, 'providers'])->middleware('throttle:60,1');
        Route::post('/transactions/{transaction}/delivery', [LogisticsController::class, 'requestDelivery'])
            ->middleware([RequireVerifiedKyc::class, IdempotencyGuard::class]);
        Route::post('/deliveries/{delivery}/accept', [LogisticsController::class, 'acceptDelivery'])
            ->middleware(RequireVerifiedKyc::class);
        Route::get('/deliveries/{delivery}', [LogisticsController::class, 'showDelivery']);
        Route::patch('/deliveries/{delivery}/status', [LogisticsController::class, 'updateStatus'])
            ->middleware(RequireVerifiedKyc::class);
        Route::get('/notifications', [NotificationController::class, 'index']);
        Route::post('/notifications/{notificationId}/read', [NotificationController::class, 'markRead']);
        Route::post('/products', [ProductController::class, 'store'])->middleware([RequireVerifiedKyc::class, IdempotencyGuard::class]);
        Route::get('/bids', [BidController::class, 'index']);
        Route::post('/products/{product}/bids', [BidController::class, 'store'])->middleware([RequireVerifiedKyc::class, IdempotencyGuard::class]);
        Route::post('/bids/{bid}/counter', [BidController::class, 'counter'])->middleware([RequireVerifiedKyc::class, IdempotencyGuard::class]);
        Route::post('/bids/{bid}/accept', [BidController::class, 'accept'])->middleware([RequireVerifiedKyc::class, IdempotencyGuard::class]);
        Route::post('/bids/{bid}/reject', [BidController::class, 'reject'])->middleware([RequireVerifiedKyc::class, IdempotencyGuard::class]);
        Route::get('/transactions/{transaction}', [PaymentController::class, 'show']);
        Route::post('/transactions/{transaction}/payments', [PaymentController::class, 'initiate'])->middleware([RequireVerifiedKyc::class, IdempotencyGuard::class]);
        Route::post('/transactions/{transaction}/delivery-confirmation', [PaymentController::class, 'confirmDelivery'])->middleware([RequireVerifiedKyc::class, IdempotencyGuard::class]);
        Route::post('/pilot-feedback', [PilotFeedbackController::class, 'store'])->middleware(IdempotencyGuard::class);
        Route::get('/pilot-feedback/summary', [PilotFeedbackController::class, 'summary']);
    });
});
