<?php

namespace App\Http\Controllers;

use App\Models\CampaignRecipient;
use App\Models\DeviceHealthEvent;
use App\Models\Message;
use App\Models\WhatsappInstance;
use Illuminate\View\View;

class HealthController extends Controller
{
    /**
     * Per-number health: connection, today's usage vs cap, and 7-day failure rate.
     * A monitoring aid only — Eagle never touches the WhatsApp session itself.
     */
    public function index(): View
    {
        $since = now()->subDays(7);

        $devices = WhatsappInstance::orderBy('name')->get()->map(function (WhatsappInstance $device) use ($since) {
            $sent = Message::where('whatsapp_instance_id', $device->id)
                ->where('direction', 'out')->where('created_at', '>=', $since)->count();

            $failed = CampaignRecipient::where('whatsapp_instance_id', $device->id)
                ->where('status', 'failed')->where('updated_at', '>=', $since)->count();
            $recentFailures = DeviceHealthEvent::where('whatsapp_instance_id', $device->id)
                ->where('event', 'send_failed')->where('created_at', '>=', now()->subMinutes(15))->count();

            $cap = $device->effectiveDailyCap();

            return [
                'device'       => $device,
                'sent_today'   => $device->sentToday(),
                'cap'          => $cap,
                'cap_percent'  => $cap > 0 ? min(100, (int) round($device->sentToday() / $cap * 100)) : 0,
                'sent_7d'      => $sent,
                'failed_7d'    => $failed,
                'failure_rate' => ($sent + $failed) > 0 ? (int) round($failed / ($sent + $failed) * 100) : 0,
                'recent_failures' => $recentFailures,
            ];
        });

        return view('health.index', compact('devices'));
    }
}
