<?php

namespace App\Http\Controllers;

use App\Models\Campaign;
use App\Models\Message;
use App\Models\TrackedLink;
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
                'recipients as delivered_count' => fn ($q) => $q->whereIn('status', ['delivered', 'read']),
                'recipients as read_count'      => fn ($q) => $q->where('status', 'read'),
                'messages as responses_count'   => fn ($q) => $q->where('direction', 'in'),
            ])
            ->latest()
            ->take(50)
            ->get();

        $inbound = fn (string $type) => Message::where('direction', 'in')->where('type', $type)
            ->when($type === 'text', fn ($q) => $q->whereNotNull('campaign_id'))->count();

        $totals = [
            'campaigns'     => Campaign::count(),
            'sent'          => (int) Campaign::sum('sent'),
            'failed'        => (int) Campaign::sum('failed'),
            'clicks'        => (int) TrackedLink::sum('clicks'),
            'replies'       => $inbound('text'),
            'poll_answers'  => $inbound('poll_response'),
            'button_clicks' => $inbound('button_response'),
        ];

        $topLinks = TrackedLink::with('campaign')
            ->where('clicks', '>', 0)
            ->orderByDesc('clicks')
            ->take(15)
            ->get();

        return view('reports.index', compact('campaigns', 'totals', 'topLinks'));
    }
}
