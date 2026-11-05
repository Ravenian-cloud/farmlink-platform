<?php

namespace App\Console\Commands;

use App\Models\PolicyPartner;
use Illuminate\Console\Command;

class IssuePolicyPartnerKey extends Command
{
    protected $signature = 'farmlink:partner-key {name} {counties* : One or more county names this partner is authorized to access}';

    protected $description = 'Create a policymaker/NGO API key and show its secret once';

    public function handle(): int
    {
        $secret = 'flp_' . bin2hex(random_bytes(32));
        $counties = array_values(array_unique(array_filter(array_map(
            static fn (string $county): string => mb_strtolower(trim($county)),
            $this->argument('counties'),
        ))));
        if ($counties === []) {
            $this->error('At least one county scope is required.');
            return self::FAILURE;
        }
        PolicyPartner::create([
            'name' => (string) $this->argument('name'),
            'counties' => $counties,
            'key_hash' => hash('sha256', $secret),
        ]);

        $this->line('Store this partner key securely; it will not be shown again:');
        $this->line($secret);

        return self::SUCCESS;
    }
}
