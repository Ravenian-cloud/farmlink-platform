<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequireKycAdministrator
{
    public function handle(Request $request, Closure $next): Response
    {
        $allowedIds = array_filter(array_map('strtolower', array_map('trim', explode(',', (string) config('services.kyc.admin_ids')))));
        abort_unless(in_array(strtolower((string) $request->user()?->id), $allowedIds, true), 403, 'This action is restricted to KYC administrators.');

        return $next($request);
    }
}
