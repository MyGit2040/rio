<?php

namespace App\Http\Controllers;

use App\Models\Campaign;
use App\Models\CampaignRecipient;
use App\Models\DeviceHealthEvent;
use App\Models\Message;
use App\Models\Suppression;
use App\Models\TrackedLink;
use App\Models\WhatsappInstance;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class ReportController extends Controller
{
    /**
     * Cross-campaign performance overview + top tracked links.
     */
    public function index(): View
    {
        $campaigns = Campaign::query()
            ->withCount([
                'recipients as sent_count' => fn ($q) => $q->whereIn('status', ['sent', 'delivered', 'read']),
                'recipients as delivered_count' => fn ($q) => $q->whereIn('status', ['delivered', 'read']),
                'recipients as read_count'      => fn ($q) => $q->where('status', 'read'),
                'messages as responses_count'   => fn ($q) => $q->where('direction', 'in'),
            ])
            ->latest()
            ->take(50)
            ->get();

        $inbound = fn (string $type) => Message::where('direction', 'in')->where('type', $type)
            ->when($type === 'text', fn ($q) => $q->whereNotNull('campaign_id'))->count();

        // `read` is quoted because it is a reserved word on MySQL; SQLite
        // accepts the backticks too, so one expression works on both.
        $recipientTotals = CampaignRecipient::selectRaw(
            "SUM(CASE WHEN status IN ('sent','delivered','read') THEN 1 ELSE 0 END) as sent,
             SUM(CASE WHEN status IN ('delivered','read') THEN 1 ELSE 0 END) as delivered,
             SUM(CASE WHEN status = 'read' THEN 1 ELSE 0 END) as `read`,
             SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed"
        )->first();

        $totals = [
            'campaigns'     => Campaign::count(),
            'sent'          => (int) ($recipientTotals->sent ?? 0),
            'delivered'     => (int) ($recipientTotals->delivered ?? 0),
            'read'          => (int) ($recipientTotals->read ?? 0),
            'failed'        => (int) ($recipientTotals->failed ?? 0),
            'clicks'        => (int) TrackedLink::sum('clicks'),
            'replies'       => $inbound('text'),
            'poll_answers'  => $inbound('poll_response'),
            'button_clicks' => $inbound('button_response'),
            'opt_outs'      => Suppression::where('source', 'opt_out')->count(),
        ];

        $deviceResults = WhatsappInstance::query()
            ->leftJoin('campaign_recipients as recipients', 'recipients.whatsapp_instance_id', '=', 'whatsapp_instances.id')
            ->select('whatsapp_instances.id', 'whatsapp_instances.name', 'whatsapp_instances.phone_number', 'whatsapp_instances.status')
            ->selectRaw("SUM(CASE WHEN recipients.status IN ('sent','delivered','read') THEN 1 ELSE 0 END) as sent")
            ->selectRaw("SUM(CASE WHEN recipients.status IN ('delivered','read') THEN 1 ELSE 0 END) as delivered")
            ->selectRaw("SUM(CASE WHEN recipients.status = 'read' THEN 1 ELSE 0 END) as `read`")
            ->selectRaw("SUM(CASE WHEN recipients.status = 'failed' THEN 1 ELSE 0 END) as failed")
            ->groupBy('whatsapp_instances.id', 'whatsapp_instances.name', 'whatsapp_instances.phone_number', 'whatsapp_instances.status')
            ->orderBy('whatsapp_instances.name')
            ->get()
            ->map(function ($device) {
                $device->recent_failures = DeviceHealthEvent::where('whatsapp_instance_id', $device->id)
                    ->where('event', 'send_failed')->where('created_at', '>=', now()->subDays(7))->count();

                return $device;
            });

        $topLinks = TrackedLink::with('campaign')
            ->where('clicks', '>', 0)
            ->orderByDesc('clicks')
            ->take(15)
            ->get();

        return view('reports.index', compact('campaigns', 'totals', 'topLinks', 'deviceResults'));
    }
}
