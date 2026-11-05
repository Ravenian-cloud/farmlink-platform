<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class KycReviewController extends Controller
{
    public function review(Request $request, string $userId): JsonResponse
    {
        if (strtolower((string) $request->user()->id) === strtolower($userId)) {
            abort(403, 'Administrators cannot review their own accounts.');
        }

        $unexpectedFields = array_diff(array_keys($request->all()), ['status', 'reason']);
        if ($unexpectedFields !== []) {
            throw ValidationException::withMessages([
                'request' => ['Only status and reason may be supplied.'],
            ]);
        }

        if (is_string($request->input('reason'))) {
            $request->merge(['reason' => trim($request->input('reason'))]);
        }

        $validated = $request->validate([
            'status' => ['required', 'string', Rule::in(['verified', 'rejected', 'pending'])],
            'reason' => ['required', 'string', 'min:10', 'max:2000'],
        ]);

        $audit = DB::transaction(function () use ($request, $userId, $validated): string {
            $target = User::query()->lockForUpdate()->findOrFail($userId);
            $previousStatus = $target->kyc_status;
            $newStatus = $validated['status'];
            $allowedTransitions = [
                'pending' => ['verified', 'rejected'],
                'rejected' => ['pending'],
            ];

            if (!in_array($newStatus, $allowedTransitions[$previousStatus] ?? [], true)) {
                throw ValidationException::withMessages([
                    'status' => ['The requested KYC status transition is not permitted.'],
                ]);
            }

            $target->kyc_status = $newStatus;
            $target->save();

            $auditId = (string) \Illuminate\Support\Str::uuid();
            DB::table('kyc_review_audits')->insert([
                'id' => $auditId,
                'reviewer_id' => $request->user()->id,
                'target_user_id' => $target->id,
                'previous_status' => $previousStatus,
                'new_status' => $newStatus,
                'reason' => $validated['reason'],
                'reviewed_at' => now(),
            ]);

            return $auditId;
        });

        return response()->json([
            'data' => [
                'user_id' => $userId,
                'kyc_status' => $validated['status'],
                'audit_id' => $audit,
            ],
        ]);
    }
}
