<?php

namespace App\Services;

use App\Models\TransportService;
use Illuminate\Database\Eloquent\Collection;

class LocationService
{
    public function nearbyProviders(string $county): Collection
    {
        return TransportService::query()
            ->with('provider:id,name,role,kyc_status')
            ->where('available', true)
            ->whereRaw('LOWER(county) = ?', [mb_strtolower(trim($county))])
            ->whereHas('provider', static fn ($query) => $query
                ->where('role', 'transport_provider')
                ->where('kyc_status', 'verified'))
            ->orderBy('base_rate_minor')
            ->limit(20)
            ->get();
    }
}
