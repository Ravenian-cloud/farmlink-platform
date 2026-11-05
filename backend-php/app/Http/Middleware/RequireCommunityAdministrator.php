<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequireCommunityAdministrator
{
    public function handle(Request $request, Closure $next): Response
    {
        $allowedIds = array_filter(array_map('trim', explode(',', (string) config('services.community.admin_ids'))));
        abort_unless(in_array((string) $request->user()?->id, $allowedIds, true), 403, 'This action is restricted to community administrators.');

        return $next($request);
    }
}
