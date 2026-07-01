<?php

namespace App\Http\Controllers;

use App\Models\Campaign;
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
            ])
            ->latest()
            ->take(50)
            ->get();

        $totals = [
            'campaigns' => Campaign::count(),
            'sent'      => (int) Campaign::sum('sent'),
            'failed'    => (int) Campaign::sum('failed'),
            'clicks'    => (int) TrackedLink::sum('clicks'),
        ];

        $topLinks = TrackedLink::with('campaign')
            ->where('clicks', '>', 0)
            ->orderByDesc('clicks')
            ->take(15)
            ->get();

        return view('reports.index', compact('campaigns', 'totals', 'topLinks'));
    }
}
