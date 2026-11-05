<?php

namespace App\Http\Middleware;

use App\Models\PolicyPartner;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuthenticatePolicyPartner
{
    public function handle(Request $request, Closure $next): Response
    {
        $providedKey = (string) $request->header('X-Partner-Key', '');
        abort_if($providedKey === '', 401, 'Partner authentication is required.');

        $partner = PolicyPartner::query()
            ->where('key_hash', hash('sha256', $providedKey))
            ->whereNull('revoked_at')
            ->first();
        abort_unless($partner, 401, 'Partner authentication failed.');

        $partner->forceFill(['last_used_at' => now()])->save();
        $request->attributes->set('policy_partner', $partner);

        return $next($request);
    }
}
