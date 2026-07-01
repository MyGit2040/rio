<?php

namespace App\Console\Commands;

use App\Models\Campaign;
use App\Services\CampaignService;
use App\Support\Tenancy;
use Illuminate\Console\Command;

class DispatchDueCampaigns extends Command
{
    protected $signature = 'campaigns:dispatch-due';

    protected $description = 'Launch scheduled campaigns whose time has arrived';

    public function handle(CampaignService $campaigns): int
    {
        $due = Campaign::withoutGlobalScopes()
            ->where('status', 'scheduled')
            ->whereNotNull('scheduled_at')
            ->where('scheduled_at', '<=', now())
            ->get();

        foreach ($due as $campaign) {
            Tenancy::run($campaign->tenant_id, fn () => $campaigns->launch($campaign));
            $this->info("Launched campaign #{$campaign->id} ({$campaign->name}).");
        }

        $this->info("{$due->count()} scheduled campaign(s) processed.");

        return self::SUCCESS;
    }
}
