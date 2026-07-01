<?php

namespace App\Http\Controllers;

use App\Http\Requests\CampaignRequest;
use App\Models\Campaign;
use App\Models\ContactGroup;
use App\Models\Template;
use App\Models\WhatsappInstance;
use App\Services\CampaignService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class CampaignController extends Controller
{
    public function __construct(private readonly CampaignService $campaigns)
    {
    }

    public function index(): View
    {
        $sent = (int) Campaign::sum('sent');
        $failed = (int) Campaign::sum('failed');

        $stats = [
            'total'        => Campaign::count(),
            'running'      => Campaign::where('status', 'sending')->count(),
            'scheduled'    => Campaign::where('status', 'scheduled')->count(),
            'completed'    => Campaign::where('status', 'completed')->count(),
            'sent'         => $sent,
            'failed'       => $failed,
            'success_rate' => ($sent + $failed) > 0 ? (int) round($sent / ($sent + $failed) * 100) : 0,
        ];

        $campaigns = Campaign::with('instance')->latest()->paginate(15);

        return view('campaigns.index', compact('campaigns', 'stats'));
    }

    public function create(): View
    {
        $settings = auth()->user()->tenant->settings ?? [];

        return view('campaigns.create', [
            'devices'   => WhatsappInstance::latest()->get(),
            'templates' => Template::orderBy('name')->get(),
            'groups'    => ContactGroup::withCount('contacts')->orderBy('name')->get(),
            'delayMin'  => (int) data_get($settings, 'bulk_delay_min', 5),
            'delayMax'  => (int) data_get($settings, 'bulk_delay_max', 15),
        ]);
    }

    public function store(CampaignRequest $request): RedirectResponse
    {
        $campaign = $this->campaigns->create($request->validated());

        if ($campaign->total === 0) {
            return redirect()->route('campaigns.show', $campaign)
                ->with('error', 'No reachable contacts matched this audience. Add contacts, then launch.');
        }

        if ($request->input('schedule') === 'now') {
            $this->campaigns->launch($campaign);

            return redirect()->route('campaigns.show', $campaign)
                ->with('success', "Campaign started — sending to {$campaign->total} contacts.");
        }

        return redirect()->route('campaigns.show', $campaign)
            ->with('success', 'Campaign scheduled for '.$campaign->scheduled_at->format('M j, Y g:i A').'.');
    }

    public function show(Campaign $campaign): View
    {
        $campaign->load('instance', 'template');

        $recipients = $campaign->recipients()
            ->with('contact', 'instance')
            ->latest('id')
            ->paginate(25);

        return view('campaigns.show', compact('campaign', 'recipients'));
    }

    public function launch(Campaign $campaign): RedirectResponse
    {
        if ($campaign->total === 0) {
            return back()->with('error', 'This campaign has no recipients.');
        }

        $this->campaigns->launch($campaign);

        return back()->with('success', 'Campaign launched.');
    }

    public function pause(Campaign $campaign): RedirectResponse
    {
        $this->campaigns->pause($campaign);

        return back()->with('success', 'Campaign paused. Launch again to resume the remaining messages.');
    }

    public function progress(Campaign $campaign): JsonResponse
    {
        return response()->json([
            'status'   => $campaign->status,
            'total'    => $campaign->total,
            'sent'     => $campaign->sent,
            'failed'   => $campaign->failed,
            'percent'  => $campaign->progressPercent(),
        ]);
    }

    public function destroy(Campaign $campaign): RedirectResponse
    {
        $campaign->delete();

        return redirect()->route('campaigns.index')->with('success', 'Campaign deleted.');
    }
}
