<?php

namespace App\Http\Controllers;

use App\Models\User;
use Firebase\JWT\JWT;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class AuthController extends Controller
{
    public function register(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:160'],
            'phone' => ['required', 'string', 'max:32', 'unique:users,phone'],
            'email' => ['nullable', 'email', 'max:254', 'unique:users,email'],
            'password' => ['required', 'string', 'min:12', 'max:128'],
            'role' => ['required', Rule::in(['farmer', 'buyer', 'supplier', 'transport_provider'])],
            'profile' => ['nullable', 'array'],
            'profile.farm_name' => ['nullable', 'string', 'max:160'],
            'profile.business_name' => ['nullable', 'string', 'max:160'],
            'profile.county' => ['nullable', 'string', 'max:100'],
        ]);

        $user = DB::transaction(function () use ($validated): User {
            $user = User::create([
                'name' => $validated['name'],
                'phone' => $validated['phone'],
                'email' => $validated['email'] ?? null,
                'password' => Hash::make($validated['password']),
                'role' => $validated['role'],
                'kyc_status' => 'pending',
            ]);

            $user->profile()->create($validated['profile'] ?? []);

            return $user;
        });

        return response()->json(['data' => $this->userPayload($user)], 201);
    }

    public function login(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'login' => ['required', 'string', 'max:254'],
            'password' => ['required', 'string', 'max:128'],
        ]);

        $user = User::query()
            ->where('phone', $validated['login'])
            ->orWhere('email', $validated['login'])
            ->first();

        if (!$user || !Hash::check($validated['password'], $user->password)) {
            return response()->json([
                'error' => ['code' => 'INVALID_CREDENTIALS', 'message' => 'The supplied credentials are invalid.'],
            ], 401);
        }

        $secret = (string) config('jwt.secret');
        if (strlen($secret) < 32) {
            throw new \RuntimeException('JWT_SECRET must contain at least 32 characters before issuing tokens.');
        }

        $now = time();
        $token = JWT::encode([
            'iss' => (string) config('app.url'),
            'sub' => (string) $user->id,
            'iat' => $now,
            'nbf' => $now,
            'exp' => $now + (int) config('jwt.ttl', 3600),
            'jti' => bin2hex(random_bytes(16)),
        ], $secret, 'HS256');

        return response()->json([
            'data' => [
                'token_type' => 'Bearer',
                'access_token' => $token,
                'expires_in' => (int) config('jwt.ttl', 3600),
                'user' => $this->userPayload($user),
            ],
        ]);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->userPayload($request->user())]);
    }

    private function userPayload(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'phone' => $user->phone,
            'email' => $user->email,
            'role' => $user->role,
            'kyc_status' => $user->kyc_status,
            'profile' => $user->profile,
        ];
    }
}
