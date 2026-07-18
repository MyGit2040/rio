<?php

namespace App\Http\Controllers;

use App\Models\Alert;
use App\Models\Campaign;
use App\Models\CampaignRecipient;
use App\Models\ChatbotRule;
use App\Models\Contact;
use App\Models\Message;
use App\Models\Template;
use App\Models\WhatsappInstance;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function index(): View|RedirectResponse
    {
        // Platform admins land in the admin panel, not a tenant dashboard.
        if (auth()->user()->isSuperAdmin()) {
            return redirect()->route('admin.dashboard');
        }

        $sent = (int) Campaign::sum('sent');
        $failed = (int) Campaign::sum('failed');

        $rulesActive = ChatbotRule::where('is_active', true)->count();
        $rulesTotal = ChatbotRule::count();

        $stats = [
            'sent'              => $sent,
            'failed'            => $failed,
            'messages_today'    => Message::where('direction', 'out')->whereDate('created_at', today())->count(),
            'devices'           => WhatsappInstance::count(),
            'connected'         => WhatsappInstance::where('status', 'open')->count(),
            'contacts'          => Contact::count(),
            'opted_in'          => Contact::marketingEligible()->count(),
            'templates'         => Template::count(),
            'rules_active'      => $rulesActive,
            'rules_total'       => $rulesTotal,
            'auto_replies'      => Message::where('direction', 'out')->whereNull('campaign_id')->count(),
            'conversations'     => (int) Message::distinct()->count('phone'),
            'campaigns_done'    => Campaign::where('status', 'completed')->count(),
            'campaigns_total'   => Campaign::count(),
        ];

        $rings = [
            'success'  => $this->rate($sent, $sent + $failed),
            'autoreply' => $this->rate($rulesActive, $rulesTotal),
            'delivery' => $this->deliveryRate(),
        ];

        $recentCampaigns = Campaign::with('instance')->latest()->take(5)->get();
        $recentMessages = Message::with('contact')->latest()->take(6)->get();
        $alerts = Alert::latest()->take(3)->get();

        // Messages sent per day (last 14 days) for the line chart.
        $byDay = Message::where('direction', 'out')
            ->where('created_at', '>=', today()->subDays(13)->startOfDay())
            ->selectRaw('DATE(created_at) as d, COUNT(*) as c')
            ->groupBy('d')->pluck('c', 'd');

        $series = collect(range(13, 0))->map(function ($i) use ($byDay) {
            $date = today()->subDays($i);

            return ['label' => $date->format('M j'), 'value' => (int) ($byDay[$date->toDateString()] ?? 0)];
        })->values();

        // Delivery breakdown for the doughnut chart.
        $b = CampaignRecipient::selectRaw('status, COUNT(*) as c')->groupBy('status')->pluck('c', 'status');
        $breakdown = [
            'Sent'      => (int) ($b['sent'] ?? 0),
            'Delivered' => (int) ($b['delivered'] ?? 0),
            'Read'      => (int) ($b['read'] ?? 0),
            'Failed'    => (int) ($b['failed'] ?? 0),
            'Pending'   => (int) ($b['pending'] ?? 0),
        ];

        return view('dashboard.index', compact('stats', 'rings', 'recentCampaigns', 'recentMessages', 'alerts', 'series', 'breakdown'));
    }

    private function rate(int $part, int $whole): int
    {
        return $whole > 0 ? (int) round($part / $whole * 100) : 0;
    }

    private function deliveryRate(): int
    {
        $row = CampaignRecipient::selectRaw(
            "SUM(CASE WHEN status IN ('delivered','read') THEN 1 ELSE 0 END) AS delivered,
             SUM(CASE WHEN status IN ('sent','delivered','read') THEN 1 ELSE 0 END) AS reached"
        )->first();

        return $this->rate((int) ($row->delivered ?? 0), (int) ($row->reached ?? 0));
    }
}
