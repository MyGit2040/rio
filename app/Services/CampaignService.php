<?php

namespace App\Services;

use App\Jobs\SendCampaignMessage;
use App\Models\Campaign;
use App\Models\Contact;
use App\Models\Suppression;
use App\Models\Template;
use App\Models\WhatsappInstance;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class CampaignService
{
    public function __construct(private readonly LinkTracker $links)
    {
    }

    /**
     * Create a campaign, snapshot its message, and build the recipient list.
     *
     * @param  array<string, mixed>  $data  Validated CampaignRequest data.
     */
    public function create(array $data): Campaign
    {
        return DB::transaction(function () use ($data) {
            $message = $this->resolveMessage($data);
            $deviceIds = array_values(array_map('intval', $data['device_ids']));

            // Per-device caps: keep only selected devices with a positive limit.
            $deviceLimits = collect($data['device_limits'] ?? [])
                ->only($deviceIds)
                ->map(fn ($v) => (int) $v)
                ->filter(fn ($v) => $v > 0)
                ->all();

            $scheduledAt = ($data['schedule'] ?? 'now') === 'later'
                ? Carbon::parse($data['scheduled_at'])
                : null;

            $campaign = Campaign::create([
                'whatsapp_instance_id' => $deviceIds[0],
                'device_ids'           => $deviceIds,
                'device_limits'        => $deviceLimits ?: null,
                'rotate_every'         => (int) ($data['rotate_every'] ?? 0),
                'template_id'          => $data['template_id'] ?? null,
                'name'                 => $data['name'],
                'type'                 => $message['type'],
                'body'                 => $message['body'],
                'footer'               => $message['footer'] ?? null,
                'variants'             => $message['variants'],
                'media_url'            => $message['media_url'],
                'media_type'           => $message['media_type'],
                'poll'                 => $message['poll'],
                'buttons'              => $message['buttons'],
                'cards'                => $message['cards'],
                'track_links'          => ! empty($data['track_links']),
                'audience'             => $data['audience'],
                'group_ids'            => $data['audience'] === 'groups' ? array_values($data['group_ids'] ?? []) : null,
                'tag'                  => $data['audience'] === 'tag' ? $data['tag'] : null,
                'min_delay'            => (int) $data['min_delay'],
                'max_delay'            => (int) $data['max_delay'],
                'max_retries'          => (int) ($data['max_retries'] ?? 3),
                'scheduled_at'         => $scheduledAt,
                'status'               => $scheduledAt ? 'scheduled' : 'draft',
            ]);

            // Link tracking: rewrite the http(s) links in the body into tracked short links.
            if ($campaign->track_links && $campaign->body) {
                $campaign->update(['body' => $this->links->wrap($campaign->body, $campaign)]);
            }

            $count = $this->buildRecipients($campaign, $data, $deviceIds);
            $campaign->update(['total' => $count]);

            return $campaign;
        });
    }

    /**
     * Dispatch the (pending) recipients as spaced-out queued jobs.
     */
    public function launch(Campaign $campaign): bool
    {
        // A double-click (or two browser requests arriving together) used to
        // dispatch every pending recipient twice. Claim the launch atomically
        // before placing any jobs on the queue.
        $claimed = Campaign::withoutGlobalScopes()
            ->whereKey($campaign->id)
            ->whereIn('status', ['draft', 'scheduled', 'paused'])
            ->update([
                'status' => 'sending',
                'started_at' => $campaign->started_at ?? now(),
            ]);

        if ($claimed !== 1) {
            return false;
        }

        $campaign->refresh();

        // Resilience: move any still-pending recipients onto the campaign's devices that
        // are CONNECTED right now. So if some numbers were locked when it paused, resuming
        // sends the remaining batches from whatever accounts have recovered — nothing is lost.
        $this->reassignPendingToConnected($campaign);

        $cumulative = 0;
        $count = 0;
        $min = max(1, $campaign->min_delay);
        $max = max($min, $campaign->max_delay);

        // Optional "sleep after every N messages" pause (rate limiting).
        $settings = $campaign->tenant?->settings ?? [];
        $sleepAfter = (int) data_get($settings, 'bulk_sleep_after', 0);
        $sleepSeconds = (int) data_get($settings, 'bulk_sleep_seconds', 0);

        $campaign->recipients()
            ->where('status', 'pending')
            ->orderBy('id')
            ->chunkById(500, function ($recipients) use (&$cumulative, &$count, $min, $max, $sleepAfter, $sleepSeconds) {
                foreach ($recipients as $recipient) {
                    SendCampaignMessage::dispatch($recipient->id)
                        ->delay(now()->addSeconds($cumulative));

                    $count++;
                    $cumulative += random_int($min, $max);

                    if ($sleepAfter > 0 && $count % $sleepAfter === 0) {
                        $cumulative += $sleepSeconds;
                    }
                }
            });

        return true;
    }

    /**
     * Copy a campaign's editable configuration into a fresh draft. Recipient
     * rows are rebuilt for the copied audience, never copied from send history.
     */
    public function duplicate(Campaign $campaign): Campaign
    {
        return DB::transaction(function () use ($campaign) {
            $copy = $campaign->replicate([
                'sent', 'failed', 'total', 'status', 'scheduled_at', 'started_at', 'completed_at',
            ]);
            $copy->fill([
                'name'         => 'Copy of '.$campaign->name,
                'status'       => 'draft',
                'scheduled_at' => null,
                'started_at'   => null,
                'completed_at' => null,
                'total'        => 0,
                'sent'         => 0,
                'failed'       => 0,
            ]);
            $copy->save();

            // Legacy campaigns require an explicit audience selection in Edit;
            // never infer that an old group campaign meant every contact.
            if (in_array($copy->audience, ['all', 'groups', 'tag'], true)) {
                $count = $this->buildRecipients($copy, [
                    'audience'  => $copy->audience,
                    'group_ids' => $copy->group_ids ?? [],
                    'tag'       => $copy->tag,
                ], $copy->device_ids ?: [$copy->whatsapp_instance_id]);
                $copy->update(['total' => $count]);
            }

            return $copy;
        });
    }

    public function pause(Campaign $campaign): void
    {
        $campaign->update(['status' => 'paused']);
    }

    /**
     * Apply edits to an existing (draft/scheduled/paused) campaign. Safe on a
     * partially-sent run: the send job re-reads the campaign row for every
     * message, so the remaining recipients pick up the new message, numbers,
     * caps and pacing on the next launch/resume — already-sent messages are
     * untouched. The audience is locked (recipients were built at creation).
     *
     * @param  array<string, mixed>  $data  Validated UpdateCampaignRequest data.
     */
    public function update(Campaign $campaign, array $data): Campaign
    {
        $deviceIds = array_values(array_map('intval', $data['device_ids']));

        $deviceLimits = collect($data['device_limits'] ?? [])
            ->only($deviceIds)
            ->map(fn ($v) => (int) $v)
            ->filter(fn ($v) => $v > 0)
            ->all();

        $body = $data['body'] ?? null;
        if ($campaign->track_links && filled($body)) {
            $body = $this->links->wrap($body, $campaign); // idempotent — existing short links kept
        }

        $payload = [
            'name'                 => $data['name'],
            'device_ids'           => $deviceIds,
            'whatsapp_instance_id' => $deviceIds[0],
            'device_limits'        => $deviceLimits ?: null,
            'rotate_every'         => (int) ($data['rotate_every'] ?? 0),
            'body'                 => $body,
            'footer'               => $data['footer'] ?? null,
            'variants'             => ($data['variants'] ?? []) ?: null,
            'audience'             => $data['audience'],
            'group_ids'            => $data['audience'] === 'groups' ? array_values($data['group_ids'] ?? []) : null,
            'tag'                  => $data['audience'] === 'tag' ? $data['tag'] : null,
            'min_delay'            => (int) $data['min_delay'],
            'max_delay'            => (int) $data['max_delay'],
            'max_retries'          => (int) ($data['max_retries'] ?? 3),
        ];

        if (in_array($campaign->type, ['media', 'poll'], true) && array_key_exists('media_url', $data)) {
            $payload['media_url'] = $data['media_url'] ?: null;
        }

        if ($campaign->type === 'poll') {
            $payload['poll'] = [
                'question' => $data['poll_question'],
                'options'  => array_values($data['poll_options'] ?? []),
                'multiple' => ! empty($data['poll_multiple']),
            ];
        }

        if ($campaign->status === 'scheduled' && ! empty($data['scheduled_at'])) {
            $payload['scheduled_at'] = Carbon::parse($data['scheduled_at']);
        }

        DB::transaction(function () use ($campaign, $payload, $data, $deviceIds) {
            $campaign->update($payload);
            $campaign->recipients()->where('status', 'pending')->delete();
            $preserved = $campaign->recipients()->count();
            $newRecipients = $this->buildRecipients($campaign, $data, $deviceIds);
            $campaign->update(['total' => $preserved + $newRecipients]);
        });

        return $campaign;
    }

    /**
     * Replace the campaign's sending numbers — e.g. swap in freshly-connected
     * devices after the original ones disconnected. Per-device caps set for
     * numbers that are removed are dropped; the still-pending recipients are
     * re-spread across the connected assignees on the next launch/resume.
     *
     * @param  array<int, int>  $deviceIds
     */
    public function assignDevices(Campaign $campaign, array $deviceIds): void
    {
        $deviceIds = array_values(array_map('intval', $deviceIds));

        $campaign->update([
            'device_ids'           => $deviceIds,
            'whatsapp_instance_id' => $deviceIds[0],
            'device_limits'        => collect($campaign->device_limits ?? [])->only($deviceIds)->all() ?: null,
        ]);
    }

    /**
     * How many of this campaign's devices are connected right now (for the UI).
     */
    public function connectedDeviceCount(Campaign $campaign): int
    {
        $ids = $campaign->device_ids ?: array_filter([$campaign->whatsapp_instance_id]);

        return empty($ids) ? 0 : WhatsappInstance::whereIn('id', $ids)->where('status', 'open')->count();
    }

    /**
     * Reassign every still-pending recipient onto the campaign's currently-connected
     * devices, spreading them by the same rule (rotate-every or sticky). If nothing is
     * connected, assignments are left untouched (the circuit breaker will pause again).
     *
     * When per-device caps are set they are HONOURED: recipients already on a
     * connected number stay put, and only those stranded on a disconnected number
     * are moved — into connected numbers that still have spare capacity. If none
     * has capacity, the recipient waits (a hard cap is never exceeded).
     */
    private function reassignPendingToConnected(Campaign $campaign): void
    {
        $ids = $campaign->device_ids ?: array_filter([$campaign->whatsapp_instance_id]);
        $connected = WhatsappInstance::whereIn('id', $ids)->where('status', 'open')->pluck('id')->all();

        if (empty($connected)) {
            return;
        }

        if (! empty($campaign->device_limits)) {
            $this->reassignRespectingCaps($campaign, $connected);

            return;
        }

        $count = count($connected);
        $rotateEvery = (int) ($campaign->rotate_every ?? 0);
        $i = 0;

        $campaign->recipients()->where('status', 'pending')->orderBy('id')
            ->chunkById(500, function ($recipients) use ($connected, $count, $rotateEvery, &$i) {
                foreach ($recipients as $recipient) {
                    $device = $rotateEvery > 0
                        ? $connected[intdiv($i, $rotateEvery) % $count]
                        : $connected[crc32($recipient->phone) % $count];

                    if ((int) $recipient->whatsapp_instance_id !== $device) {
                        $recipient->update(['whatsapp_instance_id' => $device]);
                    }
                    $i++;
                }
            });
    }

    /**
     * Move only the recipients stranded on a disconnected number onto connected
     * numbers with remaining cap capacity. Recipients already on a connected
     * number keep their slot (they were assigned within cap at build time).
     *
     * @param  array<int, int>  $connected
     */
    private function reassignRespectingCaps(Campaign $campaign, array $connected): void
    {
        $caps = $campaign->device_limits ?? [];

        // Current committed usage per device (sent + still-pending) — this is what
        // the caps count against.
        $usage = $campaign->recipients()
            ->whereIn('status', ['pending', 'sent', 'delivered', 'read'])
            ->select('whatsapp_instance_id', DB::raw('COUNT(*) as c'))
            ->groupBy('whatsapp_instance_id')->pluck('c', 'whatsapp_instance_id');

        // Spare capacity on each connected number (unlimited when cap is 0).
        $remaining = [];
        foreach ($connected as $id) {
            $cap = (int) ($caps[$id] ?? 0);
            $remaining[$id] = $cap === 0 ? PHP_INT_MAX : max(0, $cap - (int) ($usage[$id] ?? 0));
        }

        // Stranded = pending recipients NOT on a connected number.
        $campaign->recipients()->where('status', 'pending')
            ->whereNotIn('whatsapp_instance_id', $connected)
            ->orderBy('id')
            ->chunkById(500, function ($recipients) use (&$remaining) {
                foreach ($recipients as $recipient) {
                    // First connected number with spare capacity (in selection order).
                    $target = null;
                    foreach ($remaining as $id => $left) {
                        if ($left > 0) {
                            $target = $id;
                            break;
                        }
                    }

                    if ($target === null) {
                        continue; // every connected number is full — leave it waiting
                    }

                    $recipient->update(['whatsapp_instance_id' => $target]);
                    if ($remaining[$target] !== PHP_INT_MAX) {
                        $remaining[$target]--;
                    }
                }
            });
    }

    /**
     * Resolve the message payload from a template or composed text.
     *
     * @return array{type: string, body: ?string, media_url: ?string, media_type: ?string, poll: ?array}
     */
    private function resolveMessage(array $data): array
    {
        if (! empty($data['template_id'])) {
            /** @var Template $template */
            $template = Template::findOrFail($data['template_id']);

            return [
                'type'       => $template->type,
                'body'       => $template->body,
                'footer'     => $template->footer,
                'variants'   => $template->variants,
                'media_url'  => $template->media_url,
                'media_type' => $template->media_type,
                'poll'       => $template->poll,
                'buttons'    => $template->buttons,
                'cards'      => $template->cards,
            ];
        }

        return [
            'type'       => 'text',
            'body'       => $data['body'] ?? '',
            'footer'     => $data['footer'] ?? null,
            'variants'   => null,
            'media_url'  => null,
            'media_type' => null,
            'poll'       => null,
            'buttons'    => null,
            'cards'      => null,
        ];
    }

    /**
     * Insert recipient rows from the chosen audience, sticky-assigning each contact
     * to one device (same contact → same device every time). Returns the count.
     *
     * @param  array<int, int>  $deviceIds
     */
    private function buildRecipients(Campaign $campaign, array $data, array $deviceIds): int
    {
        // Campaigns use the WhatsApp verification result as the eligibility
        // gate. Opted-out contacts remain excluded at every send path.
        $query = Contact::query()->reachable()->whatsappValid();

        // On an edit, historical rows remain for reporting. Do not recreate a
        // recipient for a contact that this campaign has already processed.
        $query->whereNotExists(function ($existing) use ($campaign) {
            $existing->selectRaw('1')
                ->from('campaign_recipients as existing_campaign_recipients')
                ->whereColumn('existing_campaign_recipients.contact_id', 'contacts.id')
                ->where('existing_campaign_recipients.campaign_id', $campaign->id);
        });

        // Never build a recipient for a number on the do-not-contact (suppression) list.
        $suppressed = Suppression::pluck('phone')->all();
        if (! empty($suppressed)) {
            $query->whereNotIn('phone', $suppressed);
        }

        $audience = $data['audience'] ?? 'all';
        if ($audience === 'groups') {
            $groupIds = $data['group_ids'] ?? [];
            $query->whereHas('groups', fn ($g) => $g->whereIn('contact_groups.id', $groupIds));
        } elseif ($audience === 'tag' && ! empty($data['tag'])) {
            $query->tagged($data['tag']);
        }

        $count = 0;
        $index = 0;
        $skipped = 0;
        $referenceIds = array_fill_keys(
            $campaign->recipients()->whereNotNull('variant_ref_id')->pluck('variant_ref_id')->all(),
            true
        );
        $used = [];                                   // device id => messages assigned so far
        $deviceCount = max(1, count($deviceIds));
        $rotateEvery = (int) ($campaign->rotate_every ?? 0);
        $caps = $campaign->device_limits ?? [];       // device id => hard cap (>0)
        $hasCaps = ! empty($caps);

        // Message-variant rotation: [main body, ...variants]. Each recipient gets the
        // next slot in order so variants rotate on every single message.
        $variantCount = max(1, count(array_values(array_filter(
            array_merge([$campaign->body], $campaign->variants ?? []),
            fn ($v) => filled($v)
        ))));

        $query->select('id', 'phone')->distinct()->chunkById(500, function ($contacts) use ($campaign, $deviceIds, $deviceCount, $rotateEvery, $variantCount, $caps, $hasCaps, &$count, &$index, &$skipped, &$used, &$referenceIds) {
            $rows = [];

            foreach ($contacts as $contact) {
                if ($hasCaps) {
                    // Respect per-device caps: round-robin only across numbers that
                    // still have capacity. If every number is full, this contact is
                    // left out (the operator chose a hard cap).
                    $device = $this->nextCappedDevice($deviceIds, $caps, $used, $rotateEvery, $index);
                    if ($device === null) {
                        $skipped++;
                        continue;
                    }
                    $used[$device] = ($used[$device] ?? 0) + 1;
                } else {
                    // rotate_every > 0: send N in a row from one number, then switch to the next.
                    // rotate_every = 0: sticky — the same contact always maps to the same device.
                    $device = $rotateEvery > 0
                        ? $deviceIds[intdiv($index, $rotateEvery) % $deviceCount]
                        : $deviceIds[crc32($contact->phone) % $deviceCount];
                }

                $rows[] = [
                    'tenant_id'            => $campaign->tenant_id,
                    'campaign_id'          => $campaign->id,
                    'whatsapp_instance_id' => $device,
                    'variant_index'        => $index % $variantCount,
                    'variant_ref_id'       => $this->uniqueReferenceId($referenceIds),
                    'contact_id'           => $contact->id,
                    'phone'                => $contact->phone,
                    'status'               => 'pending',
                    'created_at'           => now(),
                    'updated_at'           => now(),
                ];
                $index++;
            }

            // SQLite caps bound variables at 999 per statement; each row uses 9
            // columns, so insert in batches of 100 (900 vars) — large audiences
            // (thousands of recipients) would otherwise blow the limit.
            foreach (array_chunk($rows, 100) as $batch) {
                $campaign->recipients()->insert($batch);
            }
            $count += count($rows);
        });

        $campaign->skippedForCapacity = $skipped;

        return $count;
    }

    /** @param array<string, bool> $used */
    private function uniqueReferenceId(array &$used): string
    {
        do {
            $reference = (string) random_int(100000, 999999);
        } while (isset($used[$reference]));

        $used[$reference] = true;

        return $reference;
    }

    /**
     * Pick the next device that still has room under its cap, round-robin across
     * the ones with capacity. Returns null when every selected number is full.
     *
     * @param  array<int, int>  $deviceIds
     * @param  array<int, int>  $caps   device id => hard cap (>0 = limited)
     * @param  array<int, int>  $used   device id => assigned so far
     */
    private function nextCappedDevice(array $deviceIds, array $caps, array $used, int $rotateEvery, int $index): ?int
    {
        $available = array_values(array_filter($deviceIds, function ($id) use ($caps, $used) {
            $cap = (int) ($caps[$id] ?? 0);

            return $cap === 0 || ($used[$id] ?? 0) < $cap;   // 0 = unlimited
        }));

        if ($available === []) {
            return null;
        }

        $n = count($available);
        $pos = $rotateEvery > 0 ? intdiv($index, $rotateEvery) % $n : $index % $n;

        return $available[$pos];
    }
}
