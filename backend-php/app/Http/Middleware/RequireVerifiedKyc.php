<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequireVerifiedKyc
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless($request->user()?->kyc_status === 'verified', 403, 'Verified KYC is required for marketplace actions.');

        return $next($request);
    }
}
