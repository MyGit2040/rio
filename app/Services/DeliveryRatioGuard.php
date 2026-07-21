<?php

namespace App\Services;

use App\Models\Alert;
use App\Models\CampaignRecipient;
use App\Models\WhatsappInstance;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * Double-tick delivery-ratio guard.
 *
 * A healthy number gets its messages delivered (two grey ticks). When WhatsApp
 * soft-bans a number, or its recipients block it, messages stay on one tick —
 * the delivery ratio collapses well before an outright ban. This watches a
 * rolling window of the most recent outbound messages per number and, if the
 * delivered ("double-ticked") share drops below the threshold, pauses that
 * number for a cool-down so it stops digging the hole deeper.
 *
 * Opt-in per workspace (bulk_delivery_guard), off by default. The cool-down is
 * stored in the cache with a self-expiring TTL, so it lifts automatically and
 * needs no schema change; sends deferred during it stay pending (nothing lost).
 */
class DeliveryRatioGuard
{
    private const WINDOW = 50;            // rolling number of recent messages judged
    private const THRESHOLD_PERCENT = 60; // minimum acceptable delivered share
    private const GRACE_MINUTES = 10;     // ignore messages too fresh to be delivered yet
    private const COOLDOWN_HOURS = 24;

    public function enabled(?array $settings): bool
    {
        return (bool) data_get($settings, 'bulk_delivery_guard', false);
    }

    private function cacheKey(int $deviceId): string
    {
        return "delivery-cooldown:{$deviceId}";
    }

    public function inCooldown(int $deviceId): bool
    {
        return Cache::has($this->cacheKey($deviceId));
    }

    public function cooldownUntil(int $deviceId): ?Carbon
    {
        $until = Cache::get($this->cacheKey($deviceId));

        return $until ? Carbon::parse($until) : null;
    }

    /**
     * Evaluate the number's rolling double-tick ratio. When a full window of
     * mature messages has delivered below the threshold, start a cool-down and
     * raise an alert. Returns true when a cool-down was (re)triggered.
     */
    public function evaluate(WhatsappInstance $device, ?array $settings = null): bool
    {
        // Already cooling down — do not recompute or re-alert.
        if ($this->inCooldown($device->id)) {
            return false;
        }

        $window = max(1, (int) data_get($settings, 'bulk_delivery_window', self::WINDOW));
        $threshold = (int) data_get($settings, 'bulk_delivery_threshold', self::THRESHOLD_PERCENT);

        // Only messages old enough to have had a fair chance at delivery, newest
        // first, capped at the window. A just-sent message is excluded so it can
        // never drag the ratio down before it has had time to land.
        $statuses = CampaignRecipient::withoutGlobalScopes()
            ->where('whatsapp_instance_id', $device->id)
            ->whereIn('status', ['sent', 'delivered', 'read'])
            ->whereNotNull('sent_at')
            ->where('sent_at', '<=', now()->subMinutes(self::GRACE_MINUTES))
            ->orderByDesc('sent_at')
            ->limit($window)
            ->pluck('status');

        // A partial window says nothing — wait until there is a full sample.
        if ($statuses->count() < $window) {
            return false;
        }

        $delivered = $statuses->filter(fn ($s) => in_array($s, ['delivered', 'read'], true))->count();
        $ratio = (int) round($delivered / $window * 100);

        if ($ratio >= $threshold) {
            return false;
        }

        $until = now()->addHours(self::COOLDOWN_HOURS);
        Cache::put($this->cacheKey($device->id), $until->toIso8601String(), $until);

        Alert::create([
            'tenant_id' => $device->tenant_id,
            'level'     => 'error',
            'title'     => "Sending number \"{$device->name}\" cooled down",
            'body'      => "Only {$ratio}% of the last {$window} messages from this number were delivered (double-ticked), below the {$threshold}% safety threshold — a likely soft-ban. Sending from it is paused for ".self::COOLDOWN_HOURS."h. Pending messages are safe and resume automatically.",
            'context'   => ['whatsapp_instance_id' => $device->id, 'delivery_ratio' => $ratio, 'window' => $window],
        ]);

        return true;
    }

    /** Clear a cool-down early (e.g. after a manual reconnect). */
    public function clear(int $deviceId): void
    {
        Cache::forget($this->cacheKey($deviceId));
    }
}
