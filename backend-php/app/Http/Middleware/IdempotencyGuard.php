<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class IdempotencyGuard
{
    public function handle(Request $request, Closure $next): Response
    {
        $key = (string) $request->header('Idempotency-Key', '');
        if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $key)) {
            return response()->json([
                'error' => ['code' => 'IDEMPOTENCY_KEY_REQUIRED', 'message' => 'Provide a valid Idempotency-Key UUID.'],
            ], 400);
        }

        $userId = (string) $request->user()->id;
        $requestHash = hash('sha256', $request->getContent());
        $inserted = DB::table('idempotency_keys')->insertOrIgnore([
            'user_id' => $userId,
            'idempotency_key' => $key,
            'request_hash' => $requestHash,
            'created_at' => now(),
            'expires_at' => now()->addDay(),
        ]);

        if ($inserted === 0) {
            $existing = DB::table('idempotency_keys')
                ->where('user_id', $userId)
                ->where('idempotency_key', $key)
                ->first();

            if (!$existing || !hash_equals($existing->request_hash, $requestHash)) {
                return response()->json([
                    'error' => ['code' => 'IDEMPOTENCY_KEY_REUSED', 'message' => 'This key was already used for a different request.'],
                ], 409);
            }
            if ($existing->response_body === null) {
                return response()->json([
                    'error' => ['code' => 'REQUEST_IN_PROGRESS', 'message' => 'A request using this key is still being processed.'],
                ], 409);
            }

            return response($existing->response_body, (int) $existing->response_status)
                ->header('Content-Type', 'application/json');
        }

        try {
            $response = $next($request);
            if ($response->isSuccessful()) {
                DB::table('idempotency_keys')
                    ->where('user_id', $userId)
                    ->where('idempotency_key', $key)
                    ->update([
                        'response_status' => $response->getStatusCode(),
                        'response_body' => $response->getContent(),
                    ]);
            } else {
                DB::table('idempotency_keys')
                    ->where('user_id', $userId)
                    ->where('idempotency_key', $key)
                    ->delete();
            }

            return $response;
        } catch (\Throwable $exception) {
            DB::table('idempotency_keys')
                ->where('user_id', $userId)
                ->where('idempotency_key', $key)
                ->delete();
            throw $exception;
        }
    }
}
