<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Firebase\JWT\Key;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateJwt
{
    public function handle(Request $request, Closure $next): Response
    {
        $authorization = (string) $request->header('Authorization', '');
        if (!preg_match('/^Bearer\s+([A-Za-z0-9._-]+)$/', $authorization, $matches)) {
            return response()->json(['error' => ['code' => 'UNAUTHENTICATED', 'message' => 'Authentication is required.']], 401);
        }

        $secret = (string) config('jwt.secret');
        if (strlen($secret) < 32) {
            Log::critical('JWT authentication is unavailable because JWT_SECRET is missing or too short.');
            return response()->json(['error' => ['code' => 'AUTHENTICATION_UNAVAILABLE', 'message' => 'Authentication is temporarily unavailable.']], 503);
        }

        try {
            $claims = \Firebase\JWT\JWT::decode($matches[1], new Key($secret, 'HS256'));
        } catch (\Firebase\JWT\ExpiredException $exception) {
            return response()->json(['error' => ['code' => 'TOKEN_EXPIRED', 'message' => 'The access token has expired.']], 401);
        } catch (\DomainException $exception) {
            return response()->json(['error' => ['code' => 'INVALID_TOKEN', 'message' => 'The access token is invalid.']], 401);
        } catch (\UnexpectedValueException $exception) {
            return response()->json(['error' => ['code' => 'INVALID_TOKEN', 'message' => 'The access token is invalid.']], 401);
        }

        if (($claims->iss ?? null) !== (string) config('app.url')) {
            return response()->json(['error' => ['code' => 'INVALID_TOKEN', 'message' => 'The access token is invalid.']], 401);
        }

        $user = User::query()->find($claims->sub ?? null);
        if (!$user) {
            return response()->json(['error' => ['code' => 'INVALID_TOKEN', 'message' => 'The access token is invalid.']], 401);
        }

        $request->setUserResolver(static fn () => $user);

        return $next($request);
    }
}
