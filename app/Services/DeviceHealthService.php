<?php

namespace App\Services;

use App\Models\Alert;
use App\Models\Campaign;
use App\Models\DeviceHealthEvent;
use App\Models\WhatsappInstance;
use Illuminate\Support\Str;

class DeviceHealthService
{
    private const FAILURE_THRESHOLD = 3;
    private const FAILURE_WINDOW_MINUTES = 15;

    /** Record a gateway failure and pause only the affected number after repeated failures. */
    public function recordFailure(WhatsappInstance $device, Campaign $campaign, string $reason): void
    {
        DeviceHealthEvent::create([
            'tenant_id' => $campaign->tenant_id,
            'whatsapp_instance_id' => $device->id,
            'campaign_id' => $campaign->id,
            'event' => 'send_failed',
            'severity' => 'warning',
            'message' => Str::limit($reason, 990),
        ]);

        $failures = DeviceHealthEvent::withoutGlobalScopes()
            ->where('whatsapp_instance_id', $device->id)
            ->where('event', 'send_failed')
            ->where('created_at', '>=', now()->subMinutes(self::FAILURE_WINDOW_MINUTES))
            ->count();

        if ($failures < self::FAILURE_THRESHOLD || $device->status === 'paused') {
            return;
        }

        // Paused devices are excluded by campaign failover immediately. The linked
        // WhatsApp account is never deleted; reconnect it and resume when ready.
        $device->update(['status' => 'paused']);

        Alert::create([
            'tenant_id' => $campaign->tenant_id,
            'level' => 'error',
            'title' => "Sending number \"{$device->name}\" paused",
            'body' => "{$failures} send failures were detected in ".self::FAILURE_WINDOW_MINUTES." minutes. This number was removed from campaign rotation. Reconnect it before using it again.",
            'context' => ['whatsapp_instance_id' => $device->id, 'campaign_id' => $campaign->id, 'failures' => $failures],
        ]);

        $activeInPool = WhatsappInstance::withoutGlobalScopes()
            ->whereIn('id', $campaign->device_ids ?: array_filter([$campaign->whatsapp_instance_id]))
            ->where('status', 'open')
            ->exists();

        if (! $activeInPool && $campaign->status === 'sending') {
            $campaign->update(['status' => 'paused']);
            Alert::create([
                'tenant_id' => $campaign->tenant_id,
                'level' => 'error',
                'title' => "Campaign \"{$campaign->name}\" paused",
                'body' => 'Every assigned sending number is unavailable after health protection. Reconnect a number, then press Resume. Pending recipients are safe.',
                'context' => ['campaign_id' => $campaign->id],
            ]);
        }
    }

    public function recordSuccess(WhatsappInstance $device, Campaign $campaign): void
    {
        DeviceHealthEvent::create([
            'tenant_id' => $campaign->tenant_id,
            'whatsapp_instance_id' => $device->id,
            'campaign_id' => $campaign->id,
            'event' => 'send_succeeded',
            'severity' => 'info',
        ]);
    }
}
