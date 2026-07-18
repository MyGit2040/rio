<?php

namespace App\Http\Controllers;

use App\Http\Requests\CampaignRequest;
use App\Http\Requests\UpdateCampaignRequest;
use App\Models\Campaign;
use App\Models\ContactGroup;
use App\Models\Template;
use App\Models\WhatsappInstance;
use App\Models\Message;
use App\Services\CampaignService;
use App\Services\PlanLimit;
use App\Support\Whatsapp;
use App\Support\Audit;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CampaignController extends Controller
{
    public function __construct(private readonly CampaignService $campaigns)
    {
    }

    public function index(Request $request): View
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
            'replies'      => Message::where('direction', 'in')->where('type', 'text')->whereNotNull('campaign_id')->count(),
            'poll_answers' => Message::where('direction', 'in')->where('type', 'poll_response')->count(),
            'button_clicks' => Message::where('direction', 'in')->where('type', 'button_response')->count(),
        ];

        $campaigns = Campaign::with('instance')
            ->when($request->filled('q'), fn ($query) => $query->where('name', 'like', '%'.$request->input('q').'%'))
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->input('status')))
            ->when($request->filled('type'), fn ($query) => $query->where('type', $request->input('type')))
            ->when($request->filled('created_from'), fn ($query) => $query->whereDate('created_at', '>=', $request->input('created_from')))
            ->when($request->filled('created_to'), fn ($query) => $query->whereDate('created_at', '<=', $request->input('created_to')))
            ->latest()
            ->paginate(15)
            ->withQueryString();

        // id => connected? map for the whole tenant (one query) so each row can
        // show assigned-vs-connected sending numbers without an N+1.
        $deviceStatus = WhatsappInstance::pluck('status', 'id')->map(fn ($s) => $s === 'open');

        return view('campaigns.index', compact('campaigns', 'stats', 'deviceStatus'));
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
                ->with('error', 'No verified WhatsApp contacts matched this audience. Use Verify WhatsApp in Contacts, then create the campaign again.');
        }

        Audit::log('campaign.created', $campaign, $campaign->name);

        // Per-device caps can leave some contacts out (hard limits). Tell the operator.
        $capWarning = $campaign->skippedForCapacity > 0
            ? " {$campaign->skippedForCapacity} contact(s) were left out because your per-number limits were reached — raise a limit or add a number to include them."
            : '';

        if ($request->input('schedule') === 'now') {
            $this->campaigns->launch($campaign);
            Audit::log('campaign.launched', $campaign, "{$campaign->total} recipients");

            return redirect()->route('campaigns.show', $campaign)
                ->with('success', "Campaign started — sending to {$campaign->total} contacts.".$capWarning);
        }

        return redirect()->route('campaigns.show', $campaign)
            ->with('success', 'Campaign scheduled for '.$campaign->scheduled_at->format('M j, Y g:i A').'.'.$capWarning);
    }

    public function edit(Campaign $campaign): View|RedirectResponse
    {
        if ($blocked = $this->guardEditable($campaign)) {
            return $blocked;
        }

        return view('campaigns.edit', [
            'campaign' => $campaign,
            'devices'  => WhatsappInstance::latest()->get(),
        ]);
    }

    public function update(UpdateCampaignRequest $request, Campaign $campaign): RedirectResponse
    {
        if ($blocked = $this->guardEditable($campaign)) {
            return $blocked;
        }

        $this->campaigns->update($campaign, $request->validated());
        Audit::log('campaign.updated', $campaign, $campaign->name);

        return redirect()->route('campaigns.show', $campaign)->with('success', $campaign->status === 'paused'
            ? 'Changes saved — press Resume to continue the remaining messages with the new settings.'
            : 'Changes saved.');
    }

    /**
     * A campaign is editable while draft/scheduled/paused; never mid-send or
     * after completion.
     */
    private function guardEditable(Campaign $campaign): ?RedirectResponse
    {
        return match ($campaign->status) {
            'sending'   => redirect()->route('campaigns.show', $campaign)
                ->with('error', 'Pause the campaign first — then you can edit its settings and press Resume.'),
            'completed' => redirect()->route('campaigns.show', $campaign)
                ->with('error', 'This campaign has finished — its settings can no longer be changed.'),
            default     => null,
        };
    }

    public function show(Campaign $campaign, Request $request): View
    {
        $campaign->load('instance', 'template');

        // --- Recipients report: dynamic filters + page size + dashboard -------
        $filters = [
            'status'   => (string) $request->query('status', ''),
            'variant'  => $request->query('variant', ''),
            'device'   => $request->query('device', ''),
            'q'        => trim((string) $request->query('q', '')),
            'from'     => (string) $request->query('from', ''),
            'to'       => (string) $request->query('to', ''),
            'per_page' => (string) $request->query('per_page', '25'),
        ];

        // Page size: 10 / 25 / 50 / 100 / all.
        if ($filters['per_page'] === 'all') {
            $perPage = max(1, $this->recipientsQuery($campaign, $filters)->count());
        } else {
            $perPage = in_array((int) $filters['per_page'], [10, 25, 50, 100], true) ? (int) $filters['per_page'] : 25;
            $filters['per_page'] = (string) $perPage;
        }

        $recipients = $this->recipientsQuery($campaign, $filters)
            ->with('contact', 'instance')
            ->latest('id')
            ->paginate($perPage)
            ->withQueryString();

        // Dashboard: each breakdown ignores its OWN filter so the counts stay a
        // full picker (e.g. status chips always show every status's count).
        $statusCounts = $this->recipientsQuery($campaign, $filters, ['status'])
            ->select('status', DB::raw('COUNT(*) as c'))->groupBy('status')->pluck('c', 'status');

        $variantCounts = $this->recipientsQuery($campaign, $filters, ['variant'])
            ->whereNotNull('variant_index')
            ->select('variant_index', DB::raw('COUNT(*) as c'))->groupBy('variant_index')->pluck('c', 'variant_index');

        $deviceCounts = $this->recipientsQuery($campaign, $filters, ['device'])
            ->whereNotNull('whatsapp_instance_id')
            ->select('whatsapp_instance_id', DB::raw('COUNT(*) as c'))->groupBy('whatsapp_instance_id')->pluck('c', 'whatsapp_instance_id');

        $filteredTotal = (int) $statusCounts->sum();
        $devices = WhatsappInstance::whereIn('id', $deviceCounts->keys())->pluck('name', 'id');

        // Assigned sending numbers + live connection status (open = connected).
        $assignedIds = $campaign->device_ids ?: array_filter([$campaign->whatsapp_instance_id]);
        $campaignDevices = WhatsappInstance::whereIn('id', $assignedIds)->orderBy('name')->get();
        $deviceSummary = [
            'assigned'     => $campaignDevices->count(),
            'connected'    => $campaignDevices->where('status', 'open')->count(),
            'disconnected' => $campaignDevices->where('status', '!=', 'open')->count(),
        ];

        // How many recipients are on each number (unfiltered) — to show usage vs cap.
        $deviceAssigned = $campaign->recipients()
            ->select('whatsapp_instance_id', DB::raw('COUNT(*) as c'))
            ->groupBy('whatsapp_instance_id')->pluck('c', 'whatsapp_instance_id');

        $pool = array_values(array_filter(array_merge([$campaign->body], $campaign->variants ?? []), 'filled'));
        $variantOptions = collect($pool)->map(fn ($b, $i) => ['value' => $i, 'label' => $i === 0 ? 'Main' : 'Variant '.$i])->all();

        $dashboard = [
            'total'       => $filteredTotal,
            'delivered'   => (int) ($statusCounts['delivered'] ?? 0) + (int) ($statusCounts['read'] ?? 0),
            'sent'        => (int) ($statusCounts['sent'] ?? 0),
            'failed'      => (int) ($statusCounts['failed'] ?? 0),
            'pending'     => (int) ($statusCounts['pending'] ?? 0),
            'statusCounts'  => $statusCounts,
            'variantCounts' => $variantCounts,
            'deviceCounts'  => $deviceCounts,
        ];

        $inbound = Message::where('campaign_id', $campaign->id)->where('direction', 'in');

        return view('campaigns.show', [
            'campaign'      => $campaign,
            'recipients'    => $recipients,
            'filters'       => $filters,
            'dashboard'     => $dashboard,
            'devices'       => $devices,
            'allDevices'    => WhatsappInstance::orderBy('name')->get(),
            'assignedIds'   => array_map('intval', $assignedIds),
            'campaignDevices' => $campaignDevices,
            'deviceSummary' => $deviceSummary,
            'deviceAssigned' => $deviceAssigned,
            'variantOptions' => $variantOptions,
            'variantStats'  => $this->variantStats($campaign),
            'trackedLinks'  => $campaign->track_links ? $campaign->trackedLinks()->orderByDesc('clicks')->get() : collect(),
            'engagement'    => [
                'replies'       => (clone $inbound)->where('type', 'text')->count(),
                'poll_answers'  => (clone $inbound)->where('type', 'poll_response')->count(),
                'button_clicks' => (clone $inbound)->where('type', 'button_response')->count(),
            ],
            'pollBreakdown' => (clone $inbound)->where('type', 'poll_response')
                ->selectRaw('body, COUNT(*) as c')->groupBy('body')->orderByDesc('c')->pluck('c', 'body'),
            'responses'     => (clone $inbound)->with('contact')->latest('id')->limit(30)->get(),
        ]);
    }

    /**
     * Build the recipients query for the report, applying the active filters.
     * $except lets a dashboard breakdown ignore its own dimension so its counts
     * stay a full picker.
     *
     * @param  array<string, mixed>  $filters
     * @param  array<int, string>    $except
     */
    private function recipientsQuery(Campaign $campaign, array $filters, array $except = []): HasMany
    {
        $q = $campaign->recipients();

        if (! in_array('status', $except, true) && $filters['status'] !== '') {
            $q->where('status', $filters['status']);
        }

        if (! in_array('variant', $except, true) && $filters['variant'] !== '' && $filters['variant'] !== null) {
            $q->where('variant_index', (int) $filters['variant']);
        }

        if (! in_array('device', $except, true) && $filters['device'] !== '') {
            $q->where('whatsapp_instance_id', $filters['device']);
        }

        if (! in_array('q', $except, true) && $filters['q'] !== '') {
            $term = $filters['q'];
            $q->where(function ($w) use ($term) {
                $w->where('phone', 'like', "%{$term}%")
                    ->orWhereHas('contact', fn ($c) => $c->where('name', 'like', "%{$term}%"));
            });
        }

        if (! in_array('date', $except, true)) {
            if ($filters['from'] !== '') {
                $q->whereDate('sent_at', '>=', $filters['from']);
            }
            if ($filters['to'] !== '') {
                $q->whereDate('sent_at', '<=', $filters['to']);
            }
        }

        return $q;
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

        $connected = $this->campaigns->connectedDeviceCount($campaign);
        if ($connected === 0) {
            return back()->with('error', "None of this campaign's WhatsApp numbers are connected. Reconnect at least one on the Devices page, then Resume — no messages are lost.");
        }

        if (PlanLimit::for(auth()->user()->tenant)->reached('monthly_messages')) {
            return back()->with('error', "You've reached your plan's monthly message limit. Upgrade on the Billing page to send more.");
        }

        $wasResume = $campaign->status === 'paused';
        $pending = $campaign->recipients()->where('status', 'pending')->count();

        if (! $this->campaigns->launch($campaign)) {
            return back()->with('error', 'This campaign is already starting or running.');
        }
        Audit::log($wasResume ? 'campaign.resumed' : 'campaign.launched', $campaign, $campaign->name);

        return back()->with('success', $wasResume
            ? "Resumed — sending the remaining {$pending} message(s) from {$connected} connected number(s)."
            : 'Campaign launched.');
    }

    public function pause(Campaign $campaign): RedirectResponse
    {
        $this->campaigns->pause($campaign);

        return back()->with('success', 'Campaign paused. Launch again to resume the remaining messages.');
    }

    /**
     * Replace the campaign's sending numbers (e.g. after the originals
     * disconnected) so Resume can continue on freshly-connected devices.
     */
    public function assignDevices(Request $request, Campaign $campaign): RedirectResponse
    {
        if ($campaign->status === 'sending') {
            return back()->with('error', 'Pause the campaign first, then change its sending numbers and Resume.');
        }

        if ($campaign->status === 'completed') {
            return back()->with('error', 'This campaign has already finished.');
        }

        $data = $request->validate([
            'device_ids'   => ['required', 'array', 'min:1'],
            'device_ids.*' => ['integer'],
        ]);

        // Tenant global scope makes this the ownership check too — a foreign
        // or deleted id simply doesn't come back.
        $ids = WhatsappInstance::whereIn('id', $data['device_ids'])->pluck('id')->all();
        if (count($ids) !== count(array_unique(array_map('intval', $data['device_ids'])))) {
            return back()->with('error', 'One of the selected numbers no longer exists — refresh and try again.');
        }

        $this->campaigns->assignDevices($campaign, $ids);
        Audit::log('campaign.devices_assigned', $campaign, count($ids).' number(s)');

        $connected = $this->campaigns->connectedDeviceCount($campaign);

        return back()->with('success', $connected > 0
            ? count($ids)." sending number(s) assigned ({$connected} connected). Press Resume to continue the remaining messages on them."
            : count($ids).' sending number(s) assigned, but none are connected yet — connect one on the Devices page, then press Resume.');
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

    /**
     * Live engagement (replies / poll answers / button clicks) for the Responses card.
     */
    public function responses(Campaign $campaign): JsonResponse
    {
        $inbound = Message::where('campaign_id', $campaign->id)->where('direction', 'in');

        return response()->json([
            'engagement' => [
                'replies'       => (clone $inbound)->where('type', 'text')->count(),
                'poll_answers'  => (clone $inbound)->where('type', 'poll_response')->count(),
                'button_clicks' => (clone $inbound)->where('type', 'button_response')->count(),
            ],
            'poll' => (clone $inbound)->where('type', 'poll_response')
                ->selectRaw('body, COUNT(*) as c')->groupBy('body')->orderByDesc('c')->get()
                ->map(fn ($r) => ['option' => $r->body, 'count' => (int) $r->c]),
            'latest' => (clone $inbound)->with('contact')->latest('id')->limit(30)->get()
                ->map(fn ($r) => [
                    'icon' => ['poll_response' => '📊', 'button_response' => '🔘'][$r->type] ?? '📩',
                    'who'  => $r->contact->name ?? '+'.$r->phone,
                    'body' => \Illuminate\Support\Str::limit($r->body, 120),
                    'ago'  => $r->created_at?->diffForHumans(),
                ]),
        ]);
    }

    public function destroy(Campaign $campaign): RedirectResponse
    {
        $campaign->delete();

        return redirect()->route('campaigns.index')->with('success', 'Campaign deleted.');
    }

    public function bulk(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'action' => ['required', 'in:delete'],
            'ids'    => ['required', 'array', 'min:1'],
            'ids.*'  => ['integer'],
        ]);

        $count = Campaign::whereIn('id', $data['ids'])->count();
        Campaign::whereIn('id', $data['ids'])->delete();

        return back()->with('success', "{$count} campaign(s) deleted.");
    }

    /**
     * Re-queue only the recipients that failed.
     */
    public function retryFailed(Campaign $campaign): RedirectResponse
    {
        $updates = ['status' => 'pending'];
        // Keep poll-prelude failure details: the job uses them to retry the
        // native poll without sending the explanatory text a second time.
        if ($campaign->type !== 'poll') {
            $updates += ['attempts' => 0, 'error' => null];
        }
        $count = $campaign->recipients()->where('status', 'failed')->update($updates);

        if ($count === 0) {
            return back()->with('error', 'No failed messages to retry.');
        }

        $campaign->update([
            'failed' => $campaign->recipients()->where('status', 'failed')->count(),
            'status' => 'paused',
        ]);
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

        $engine = Whatsapp::forInstance($device);
        $body = str_replace(['{{name}}', '{{phone}}'], ['there', $number], (string) $campaign->body);

        // A poll can't carry text/media, so (like a real send) send the message FIRST —
        // the image with the caption, or plain text — then the poll below it.
        if ($campaign->type === 'poll') {
            if ($campaign->media_url) {
                $prelude = $engine->sendMedia($device->instance_name, $number, $campaign->media_type ?: 'image', $campaign->media_url, $body !== '' ? $body : null);
            } elseif (trim($body) !== '') {
                $prelude = $engine->sendText($device->instance_name, $number, $body);
            } else {
                $prelude = ['ok' => true, 'error' => null];
            }

            if (! $prelude['ok']) {
                return back()->with('error', 'Test failed before the poll could be sent: '.($prelude['error'] ?? 'unknown error'));
            }
        }

        $result = match ($campaign->type) {
            'media' => $engine->sendMedia($device->instance_name, $number, $campaign->media_type ?: 'image', $campaign->media_url, $body),
            'poll'  => $engine->sendPoll($device->instance_name, $number, $campaign->poll['question'] ?? 'Poll', $campaign->poll['options'] ?? []),
            'buttons' => $engine->sendButtons($device->instance_name, $number, data_get($campaign->buttons, 'title', 'Menu'), $body, data_get($campaign->buttons, 'footer'), collect(data_get($campaign->buttons, 'items', []))->map(fn ($b) => ['type' => $b['type'] ?? 'reply', 'displayText' => $b['text'] ?? ''])->all()),
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
