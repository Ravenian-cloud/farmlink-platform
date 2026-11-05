<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class PilotFeedbackController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'category' => ['required', Rule::in(['bug', 'negotiation', 'recommendation', 'general'])],
            'rating' => ['nullable', 'integer', 'between:1,5'],
            'negotiation_rating' => ['nullable', 'integer', 'between:1,5'],
            'recommendation_accuracy_rating' => ['nullable', 'integer', 'between:1,5'],
            'summary' => ['nullable', 'string', 'max:160'],
            'details' => ['required', 'string', 'min:10', 'max:5000'],
            'app_version' => ['nullable', 'string', 'max:40'],
        ]);

        if ($validated['category'] === 'negotiation' && !isset($validated['negotiation_rating'])) {
            return response()->json([
                'error' => [
                    'code' => 'VALIDATION_FAILED',
                    'message' => 'Negotiation feedback requires a negotiation rating.',
                    'details' => ['negotiation_rating' => ['This rating is required for negotiation feedback.']],
                ],
            ], 422);
        }
        if ($validated['category'] === 'recommendation' && !isset($validated['recommendation_accuracy_rating'])) {
            return response()->json([
                'error' => [
                    'code' => 'VALIDATION_FAILED',
                    'message' => 'Recommendation feedback requires an accuracy rating.',
                    'details' => ['recommendation_accuracy_rating' => ['This rating is required for recommendation feedback.']],
                ],
            ], 422);
        }

        $id = (string) \Illuminate\Support\Str::uuid();
        DB::table('pilot_feedback')->insert([
            'id' => $id,
            'user_id' => $request->user()->id,
            'category' => $validated['category'],
            'rating' => $validated['rating'] ?? null,
            'negotiation_rating' => $validated['negotiation_rating'] ?? null,
            'recommendation_accuracy_rating' => $validated['recommendation_accuracy_rating'] ?? null,
            'summary' => $validated['summary'] ?? null,
            'details' => $validated['details'],
            'app_version' => $validated['app_version'] ?? null,
            'created_at' => now(),
        ]);

        return response()->json(['data' => [
            'id' => $id,
            'category' => $validated['category'],
            'created_at' => now()->toIso8601String(),
        ]], 201);
    }

    public function summary(Request $request): JsonResponse
    {
        $allowedIds = array_filter(array_map('trim', explode(',', (string) config('services.pilot.feedback_admin_ids', ''))));
        abort_unless(in_array((string) $request->user()->id, $allowedIds, true), 403);

        $summary = DB::table('pilot_feedback')
            ->selectRaw('category, COUNT(*) AS responses, AVG(rating) AS average_rating, AVG(negotiation_rating) AS average_negotiation_rating, AVG(recommendation_accuracy_rating) AS average_recommendation_accuracy')
            ->groupBy('category')
            ->orderBy('category')
            ->get()
            ->map(static fn (object $row): array => [
                'category' => $row->category,
                'responses' => (int) $row->responses,
                'average_rating' => $row->average_rating === null ? null : round((float) $row->average_rating, 2),
                'average_negotiation_rating' => $row->average_negotiation_rating === null ? null : round((float) $row->average_negotiation_rating, 2),
                'average_recommendation_accuracy' => $row->average_recommendation_accuracy === null ? null : round((float) $row->average_recommendation_accuracy, 2),
            ]);

        return response()->json(['data' => $summary]);
    }
}
