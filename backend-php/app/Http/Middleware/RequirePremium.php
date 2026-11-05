<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequirePremium
{
    public function handle(Request $request, Closure $next): Response
    {
        $subscription = $request->user()?->subscription;
        abort_unless($subscription?->isActive(), 403, 'An active Premium subscription is required.');

        return $next($request);
    }
}
