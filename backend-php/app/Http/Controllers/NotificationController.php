<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class NotificationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $notifications = DB::table('farm_notifications')
            ->where('user_id', $request->user()->id)
            ->orderByDesc('created_at')
            ->paginate(min(max((int) $request->query('per_page', 20), 1), 100));

        return response()->json($notifications);
    }

    public function markRead(Request $request, string $notificationId): JsonResponse
    {
        $updated = DB::table('farm_notifications')
            ->where('id', $notificationId)
            ->where('user_id', $request->user()->id)
            ->whereNull('read_at')
            ->update(['read_at' => now()]);

        if ($updated === 0 && !DB::table('farm_notifications')
            ->where('id', $notificationId)
            ->where('user_id', $request->user()->id)
            ->exists()) {
            abort(404);
        }

        return response()->json(['data' => ['id' => $notificationId, 'read' => true]]);
    }
}
