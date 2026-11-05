<?php

namespace App\Console\Commands;

use App\Models\PolicyPartner;
use Illuminate\Console\Command;

class RevokePolicyPartnerKey extends Command
{
    protected $signature = 'farmlink:partner-revoke {id}';

    protected $description = 'Revoke an issued policymaker/NGO API key';

    public function handle(): int
    {
        $partner = PolicyPartner::query()->find($this->argument('id'));
        if (!$partner) {
            $this->error('Partner was not found.');
            return self::FAILURE;
        }

        $partner->forceFill(['revoked_at' => now()])->save();
        $this->info('Partner API key revoked.');

        return self::SUCCESS;
    }
}
