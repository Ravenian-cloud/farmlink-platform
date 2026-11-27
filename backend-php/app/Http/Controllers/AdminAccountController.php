,<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class AdminAccountController extends Controller
{
    public function changePassword(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'current_password' => ['required', 'string', 'max:128'],
            'password' => ['required', 'string', 'min:12', 'max:128', 'confirmed'],
        ]);

        abort_unless(
            Hash::check($validated['current_password'], $request->user()->password),
            422,
            'The current password is incorrect.',
        );

        $request->user()->forceFill([
            'password' => Hash::make($validated['password']),
        ])->save();

        return response()->json(['data' => ['message' => 'Password changed successfully.']]);
    }
}
