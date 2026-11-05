<?php

namespace App\Http\Controllers;

use App\Models\UserProfile;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProfileController extends Controller
{
    public function update(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'county' => ['required', 'string', 'min:2', 'max:100'],
        ]);
        $profile = UserProfile::updateOrCreate(
            ['user_id' => $request->user()->id],
            ['county' => mb_strtolower(trim($validated['county']))],
        );

        return response()->json(['data' => $profile]);
    }
}
