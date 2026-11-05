<?php

namespace App\Http\Controllers;

use App\Models\FarmerImpactObservation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FarmerImpactController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        abort_unless($request->user()->role === 'farmer', 403, 'Only farmer accounts can view impact observations.');

        return response()->json([
            'data' => FarmerImpactObservation::query()
                ->where('farmer_id', $request->user()->id)
                ->orderByDesc('reporting_period')
                ->get(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user->role === 'farmer', 403, 'Only farmer accounts can submit impact observations.');
        $county = mb_strtolower(trim((string) $user->profile?->county));
        abort_if($county === '', 422, 'Add your county to your farmer profile before submitting an impact report.');

        $validated = $request->validate([
            'reporting_period' => [
                'required',
                'date_format:Y-m-d',
                'before_or_equal:today',
                'after_or_equal:' . now()->subYears(3)->startOfMonth()->toDateString(),
                function (string $attribute, mixed $value, \Closure $fail): void {
                    if (substr((string) $value, 8, 2) !== '01') {
                        $fail('Use the first day of the month as the reporting period.');
                    }
                },
            ],
            'baseline_monthly_income_minor' => ['required', 'integer', 'min:0', 'max:1000000000000'],
            'current_monthly_income_minor' => ['required', 'integer', 'min:0', 'max:1000000000000'],
            'baseline_post_harvest_loss_percent' => ['required', 'numeric', 'between:0,100'],
            'current_post_harvest_loss_percent' => ['required', 'numeric', 'between:0,100'],
            'consent' => ['required', 'accepted'],
        ]);

        $observation = FarmerImpactObservation::updateOrCreate(
            [
                'farmer_id' => $user->id,
                'reporting_period' => $validated['reporting_period'],
            ],
            [
                'county' => $county,
                'baseline_monthly_income_minor' => $validated['baseline_monthly_income_minor'],
                'current_monthly_income_minor' => $validated['current_monthly_income_minor'],
                'baseline_post_harvest_loss_percent' => $validated['baseline_post_harvest_loss_percent'],
                'current_post_harvest_loss_percent' => $validated['current_post_harvest_loss_percent'],
                'currency' => 'KES',
                'consented_at' => now(),
            ],
        );

        return response()->json(['data' => $observation], 201);
    }
}
