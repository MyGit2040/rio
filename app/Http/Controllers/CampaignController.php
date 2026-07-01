<?php

namespace App\Http\Controllers;

use App\Http\Requests\CampaignRequest;
use App\Models\Campaign;
use App\Models\ContactGroup;
use App\Models\Template;
use App\Models\WhatsappInstance;
use App\Services\CampaignService;
use App\Services\EvolutionApiService;
use App\Support\Audit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

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

        Audit::log('campaign.created', $campaign, $campaign->name);

        if ($request->input('schedule') === 'now') {
            $this->campaigns->launch($campaign);
            Audit::log('campaign.launched', $campaign, "{$campaign->total} recipients");

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

        return view('campaigns.show', [
            'campaign'     => $campaign,
            'recipients'   => $recipients,
            'variantStats' => $this->variantStats($campaign),
            'trackedLinks' => $campaign->track_links ? $campaign->trackedLinks()->orderByDesc('clicks')->get() : collect(),
        ]);
    }

    /**
     * A/B report: how each message variant performed (sent / delivered+read).
     *
     * @return array<int, array<string, mixed>>
     */
    private function variantStats(Campaign $campaign): array
    {
        $pool = array_values(array_filter(array_merge([$campaign->body], $campaign->variants ?? []), 'filled'));

        if (count($pool) < 2) {
            return []; // no A/B to report
        }

        $rows = $campaign->recipients()
            ->selectRaw("variant_index,
                COUNT(*) as total,
                SUM(CASE WHEN status IN ('delivered','read') THEN 1 ELSE 0 END) as delivered")
            ->whereNotNull('variant_index')
            ->groupBy('variant_index')
            ->get()
            ->keyBy('variant_index');

        return collect($pool)->map(function ($body, $i) use ($rows) {
            $total = (int) ($rows[$i]->total ?? 0);
            $delivered = (int) ($rows[$i]->delivered ?? 0);

            return [
                'label'     => $i === 0 ? 'Main' : 'Variant '.$i,
                'body'      => $body,
                'sent'      => $total,
                'delivered' => $delivered,
                'rate'      => $total > 0 ? (int) round($delivered / $total * 100) : 0,
            ];
        })->all();
    }

    public function launch(Campaign $campaign): RedirectResponse
    {
        if ($campaign->total === 0) {
            return back()->with('error', 'This campaign has no recipients.');
        }

        $this->campaigns->launch($campaign);
        Audit::log('campaign.launched', $campaign, $campaign->name);

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

    /**
     * Re-queue only the recipients that failed.
     */
    public function retryFailed(Campaign $campaign): RedirectResponse
    {
        $count = $campaign->recipients()->where('status', 'failed')->update(['status' => 'pending', 'attempts' => 0, 'error' => null]);

        if ($count === 0) {
            return back()->with('error', 'No failed messages to retry.');
        }

        $campaign->update(['failed' => $campaign->recipients()->where('status', 'failed')->count()]);
        $this->campaigns->launch($campaign);

        return back()->with('success', "Re-queued {$count} failed message(s).");
    }

    /**
     * Send the campaign's message to a single test number (does not touch recipients).
     */
    public function test(Request $request, Campaign $campaign): RedirectResponse
    {
        $number = preg_replace('/\D+/', '', (string) $request->validate(['phone' => ['required', 'string', 'max:32']])['phone']);
        $device = $campaign->instance;

        if (! $device || ! $device->isConnected()) {
            return back()->with('error', 'The campaign\'s device is not connected.');
        }

        $engine = EvolutionApiService::forInstance($device);
        $body = str_replace(['{{name}}', '{{phone}}'], ['there', $number], (string) $campaign->body);

        $result = match ($campaign->type) {
            'media' => $engine->sendMedia($device->instance_name, $number, $campaign->media_type ?: 'image', $campaign->media_url, $body),
            'poll'  => $engine->sendPoll($device->instance_name, $number, $campaign->poll['question'] ?? 'Poll', $campaign->poll['options'] ?? []),
            default => $engine->sendText($device->instance_name, $number, $body ?: 'Test message'),
        };

        return $result['ok']
            ? back()->with('success', 'Test sent to +'.$number.'.')
            : back()->with('error', 'Test failed: '.($result['error'] ?? 'unknown error'));
    }

    /**
     * Download the recipient results as a CSV.
     */
    public function export(Campaign $campaign): StreamedResponse
    {
        return response()->streamDownload(function () use ($campaign) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['Contact', 'Phone', 'Device', 'Status', 'Sent at', 'Note']);

            $campaign->recipients()->with('contact', 'instance')->chunk(500, function ($rows) use ($out) {
                foreach ($rows as $r) {
                    fputcsv($out, [
                        $r->contact->name ?? '', $r->phone, $r->instance->name ?? '',
                        $r->status, $r->sent_at?->format('Y-m-d H:i'), $r->error,
                    ]);
                }
            });

            fclose($out);
        }, 'campaign-'.$campaign->id.'-results.csv', ['Content-Type' => 'text/csv']);
    }
}
