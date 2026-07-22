<?php

namespace App\Console\Commands;

use App\Models\Alert;
use App\Models\WhatsappInstance;
use App\Support\Whatsapp;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Reconcile each WhatsApp number's stored status with the gateway's REAL
 * session state.
 *
 * Why this exists: a banned / logged-out account can keep "sending" forever.
 * The stored status only changes via webhooks, and the failure-based health
 * guard (DeviceHealthService) needs 3 FAILED sends in 15 minutes — but a dead
 * session that silently swallows messages never fails, so the number stays
 * "connected" in the app and campaigns keep feeding it. This command asks the
 * gateway directly and flips the stored status to match reality:
 *
 *  - gateway says NOT connected on two consecutive runs → status 'close'
 *    (+ an alert). Campaign rotation then skips the number automatically
 *    (resolveSendingDevice only picks 'open' devices) and failover/circuit
 *    breaker take over — nothing is lost.
 *  - gateway says connected while the app shows 'close' → healed back to
 *    'open' (covers a missed reconnect webhook).
 *
 * Deliberately conservative:
 *  - Two consecutive non-open sightings are required before disconnecting
 *    (anti-flap: a session mid-restart reads "connecting" for a moment).
 *  - A network error / unreachable gateway is NO verdict — the device is
 *    skipped, never flipped on silence.
 *  - 'connecting' devices (QR-link flow) and 'paused' devices (health
 *    protection — a deliberate, manually-lifted state) are never touched.
 */
class SyncDeviceStatuses extends Command
{
    protected $signature = 'devices:sync-status';

    protected $description = "Mark numbers the gateway reports as disconnected/logged-out (banned accounts) so campaigns stop using them";

    /** Consecutive non-open sightings required before a connected number is flipped. */
    private const STRIKES_TO_DISCONNECT = 2;

    public function handle(): int
    {
        $devices = WhatsappInstance::withoutGlobalScopes()
            ->whereIn('status', ['open', 'close'])
            ->with('tenant')
            ->get();

        foreach ($devices as $device) {
            try {
                $state = data_get(
                    Whatsapp::forInstance($device)->connectionState($device->instance_name),
                    'instance.state'
                );
            } catch (\RuntimeException $e) {
                // "…was not found." — the session no longer exists at the gateway:
                // definitively dead (logged out, removed, or a banned account whose
                // session was cleaned up). Anything else (config mismatch) is not
                // evidence about the session, so it is skipped.
                if (! str_contains($e->getMessage(), 'was not found')) {
                    continue;
                }
                $state = 'close';
            } catch (\Throwable $e) {
                // Gateway unreachable / HTTP failure: no verdict. Never flap on silence.
                Log::warning('devices:sync-status skipped a device (gateway unreachable)', [
                    'device' => $device->instance_name,
                    'error'  => $e->getMessage(),
                ]);

                continue;
            }

            $state === 'open'
                ? $this->markConnected($device)
                : $this->strikeAndDisconnect($device);
        }

        return self::SUCCESS;
    }

    /** The gateway confirms the session is live: clear strikes, heal a stale 'close'. */
    private function markConnected(WhatsappInstance $device): void
    {
        Cache::forget($this->strikeKey($device));

        if ($device->status !== 'open') {
            $device->update([
                'status'       => 'open',
                'connected_at' => $device->connected_at ?? now(),
            ]);

            $this->info("{$device->name}: healed to connected (gateway reports the session is live).");
        }
    }

    /**
     * The gateway says the session is NOT connected. First sighting arms a
     * strike; a second consecutive sighting disconnects the number and alerts.
     */
    private function strikeAndDisconnect(WhatsappInstance $device): void
    {
        if ($device->status !== 'open') {
            Cache::forget($this->strikeKey($device));

            return; // already disconnected — nothing to do
        }

        $strikes = (int) Cache::get($this->strikeKey($device), 0) + 1;

        if ($strikes < self::STRIKES_TO_DISCONNECT) {
            Cache::put($this->strikeKey($device), $strikes, now()->addMinutes(10));

            return;
        }

        Cache::forget($this->strikeKey($device));
        $device->update(['status' => 'close']);

        Alert::create([
            'tenant_id' => $device->tenant_id,
            'level'     => 'error',
            'title'     => "\"{$device->name}\" is no longer connected",
            'body'      => 'The gateway reports this WhatsApp number as disconnected (logged out, session lost, or the account was banned). '
                .'It was removed from campaign rotation — remaining messages fail over to your other connected numbers. '
                .'Reconnect it on the Devices page when it is available again.',
            'context'   => ['whatsapp_instance_id' => $device->id],
        ]);

        Log::error("devices:sync-status: '{$device->name}' marked disconnected — gateway reports the session is not connected.", [
            'whatsapp_instance_id' => $device->id,
            'tenant_id'            => $device->tenant_id,
        ]);

        $this->warn("{$device->name}: marked disconnected (gateway says the session is not connected).");
    }

    private function strikeKey(WhatsappInstance $device): string
    {
        return 'device-status-strike:'.$device->id;
    }
}
