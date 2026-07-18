<?php

namespace App\Jobs;

use App\Models\Alert;
use App\Models\Campaign;
use App\Models\CampaignRecipient;
use App\Models\Message;
use App\Models\Suppression;
use App\Models\WhatsappInstance;
use App\Services\PlanLimit;
use App\Support\SendingWindow;
use App\Support\Tenancy;
use App\Support\Whatsapp;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class SendCampaignMessage implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;
    public int $backoff = 30;

    public function __construct(public int $recipientId)
    {
    }

    public function handle(): void
    {
        $recipient = CampaignRecipient::withoutGlobalScopes()->find($this->recipientId);

        if (! $recipient || $recipient->status !== 'pending') {
            return;
        }

        $campaign = Campaign::withoutGlobalScopes()->find($recipient->campaign_id);

        if (! $campaign || in_array($campaign->status, ['paused', 'completed'], true)) {
            return;
        }

        Tenancy::run($campaign->tenant_id, function () use ($recipient, $campaign) {
            $this->send($recipient, $campaign);
        });
    }

    private function send(CampaignRecipient $recipient, Campaign $campaign): void
    {
        // The sticky-assigned device — or, if it's disconnected, another connected
        // device from this campaign's pool (automatic failover; see resolveSendingDevice).
        $instance = $this->resolveSendingDevice($recipient, $campaign);

        if (! $instance) {
            // Nothing in this campaign's pool is connected — pause (circuit breaker);
            // recipients stay pending and resume when a device reconnects.
            $this->tripCircuitBreaker($campaign, ($recipient->instance ?: $campaign->instance)?->name);

            return;
        }

        // Quiet hours: hold this send until the next allowed window (stays pending).
        if ($next = SendingWindow::nextAllowed($campaign->tenant?->settings)) {
            self::dispatch($recipient->id)->delay($next->addSeconds(random_int(1, 300)));

            return;
        }

        // Per-device daily cap: if this number has sent its allowance today,
        // defer this contact to tomorrow (stays pending — nothing is lost).
        if ($instance->atDailyCap()) {
            self::dispatch($recipient->id)->delay(now()->addDay()->startOfDay()->addMinutes(random_int(1, 90)));

            return;
        }

        // Plan monthly-message cap: pause the campaign cleanly when the limit is hit.
        // Remaining recipients stay pending — resumable next month or after an upgrade.
        if ($campaign->tenant && PlanLimit::for($campaign->tenant)->reached('monthly_messages')) {
            $campaign->update(['status' => 'paused']);

            return;
        }

        $engine = Whatsapp::forInstance($instance);
        $contact = $recipient->contact;
        $number = $recipient->phone;

        // Do-not-contact list: never message a suppressed number.
        if (Suppression::has($number)) {
            $recipient->update(['status' => 'failed', 'error' => 'On suppression list (do-not-contact)']);
            $campaign->increment('failed');
            $this->finaliseIfDone($campaign);

            return;
        }

        $spinRandom = (bool) data_get($campaign->tenant?->settings, 'bulk_spintax', true);

        // Rotate across author-written message variants (A/B copy), then spin + personalize.
        // The variant slot is assigned round-robin at build time so each successive
        // message uses the next variant in order (not random).
        [$variantIndex, $body] = $this->variantFor($campaign, $recipient->variant_index);
        $referenceId = $this->ensureReferenceId($recipient);

        // Footer (signature) is stored separately and merged onto the message here, so
        // every variant stays clean and the footer is appended automatically to all.
        if (($footer = trim((string) $campaign->footer)) !== '') {
            $body = rtrim((string) $body)."\n\n".$footer;
        }

        // A poll can't hold text/media itself, so send the message FIRST — the image with
        // the full text as its caption (bound together), or plain text — then the poll below.
        if ($campaign->type === 'poll') {
            $caption = $this->personalize($body, $contact, $number, $spinRandom, $referenceId);
            $hasPrelude = (bool) $campaign->media_url || trim($caption) !== '';

            // This recipient has already completed the first stage. Continue only
            // with the poll, even if this job is retried or delivered twice.
            if ($recipient->prelude_sent_at) {
                $hasPrelude = false;
            }

            // A retry after the gateway's post-send 500 must continue with the
            // poll, not send the already-delivered explanatory text again.
            $preludeAlreadyAttempted = $recipient->attempts > 0
                && str_starts_with((string) $recipient->error, 'Poll prelude failed:');

            if (! $hasPrelude || $preludeAlreadyAttempted) {
                $prelude = ['ok' => true, 'error' => null];
            } elseif ($campaign->media_url) {
                $prelude = $engine->sendMedia(
                    $instance->instance_name,
                    $number,
                    $campaign->media_type ?: 'image',
                    $campaign->media_url,
                    $caption !== '' ? $caption : null,
                );
            } elseif (trim($caption) !== '') {
                $prelude = $engine->sendText($instance->instance_name, $number, $caption);
            } else {
                $prelude = ['ok' => true, 'error' => null];
            }

            // A poll is deliberately sent beneath its explanatory text/image.
            // Do not send a detached poll when its required first step failed.
            if (! $prelude['ok']) {
                $this->markFailed($recipient, $campaign, 'Poll prelude failed: '.($prelude['error'] ?? 'unknown error'));
                $this->finaliseIfDone($campaign);

                return;
            }

            // Do not hold a queue worker with sleep(). Persist the confirmed first
            // stage and queue the native poll after the campaign's random delay.
            if ($hasPrelude && ! $recipient->prelude_sent_at) {
                $recipient->update(['prelude_sent_at' => now(), 'error' => null]);
                self::dispatch($recipient->id)->delay(now()->addSeconds($this->pollPreludeDelay($campaign)));

                return;
            }
        }

        $result = match ($campaign->type) {
            'media' => $engine->sendMedia(
                $instance->instance_name,
                $number,
                $campaign->media_type ?: 'image',
                $campaign->media_url,
                $this->personalize($body, $contact, $number, $spinRandom, $referenceId),
            ),
            'poll' => $engine->sendPoll(
                $instance->instance_name,
                $number,
                $campaign->poll['question'] ?? 'Poll',
                $campaign->poll['options'] ?? [],
                ! empty($campaign->poll['multiple']) ? max(2, count($campaign->poll['options'] ?? [])) : 1,
            ),
            'buttons' => $engine->sendButtons(
                $instance->instance_name,
                $number,
                data_get($campaign->buttons, 'title', 'Menu'),
                $this->personalize($body, $contact, $number, $spinRandom, $referenceId),
                data_get($campaign->buttons, 'footer'),
                $this->mapButtons($campaign),
            ),
            'carousel' => $this->sendCarousel($engine, $instance, $number, $campaign, $contact, $spinRandom, $referenceId),
            default => $engine->sendText(
                $instance->instance_name,
                $number,
                $this->personalize($body, $contact, $number, $spinRandom, $referenceId),
            ),
        };

        if ($result['ok']) {
            $recipient->update([
                'status'        => 'sent',
                'variant_index' => $variantIndex,
                'message_id'    => $result['message_id'],
                'sent_at'       => now(),
                'error'         => $result['error'] ?? null,   // may hold a non-fatal note (carousel fallback)
            ]);
            $campaign->increment('sent');

            Message::create([
                'whatsapp_instance_id' => $instance->id,
                'contact_id'           => $recipient->contact_id,
                'campaign_id'          => $campaign->id,
                'direction'            => 'out',
                'phone'                => $number,
                'type'                 => $campaign->type,
                'body'                 => $campaign->body,
                'status'               => 'sent',
                'message_id'           => $result['message_id'],
            ]);
        } else {
            $this->markFailed($recipient, $campaign, $result['error'] ?? 'Unknown error');
        }

        $this->finaliseIfDone($campaign);
    }

    /**
     * The device that should send this message: the sticky-assigned one if it's
     * connected; otherwise (failover on — default) another connected device from the
     * campaign's pool, and the contact is re-stuck to it so its next message uses it
     * too. Returns null when no device in the pool is connected.
     */
    private function resolveSendingDevice(CampaignRecipient $recipient, Campaign $campaign): ?WhatsappInstance
    {
        $poolIds = $campaign->device_ids ?: array_filter([$campaign->whatsapp_instance_id]);

        // Connected numbers, kept in the campaign's pool order.
        $connectedIds = WhatsappInstance::whereIn('id', $poolIds)->where('status', 'open')->pluck('id')->all();
        $connected = array_values(array_filter(
            array_map('intval', $poolIds),
            fn ($id) => in_array($id, array_map('intval', $connectedIds), true)
        ));

        if (empty($connected)) {
            return null; // nothing connected → circuit breaker (campaign pauses, nothing lost)
        }

        // Stick to the assigned number while it's active.
        $sticky = (int) ($recipient->whatsapp_instance_id ?: $campaign->whatsapp_instance_id);
        if ($sticky && in_array($sticky, $connected, true)) {
            return WhatsappInstance::find($sticky);
        }

        // Assigned number is down: only rotate away if failover is on (default).
        if (! (bool) data_get($campaign->tenant?->settings, 'bulk_device_failover', true)) {
            return null;
        }

        // Rotate to the next connected number — the pointer advances every message, so
        // successive sends spread across all live numbers instead of piling on one.
        $deviceId = $connected[(int) ($campaign->sent + $campaign->failed) % count($connected)];
        $recipient->update(['whatsapp_instance_id' => $deviceId]);

        return WhatsappInstance::find($deviceId);
    }

    private function markFailed(CampaignRecipient $recipient, Campaign $campaign, string $error): void
    {
        $recipient->increment('attempts');

        // Retry transient failures up to the campaign's max_retries (with backoff).
        if ($recipient->attempts <= $campaign->max_retries) {
            $recipient->update(['status' => 'pending', 'error' => Str::limit($error, 990)]);
            self::dispatch($recipient->id)->delay(now()->addSeconds(30 * $recipient->attempts));

            return;
        }

        $recipient->update(['status' => 'failed', 'error' => Str::limit($error, 990)]);
        $campaign->increment('failed');
        $this->finaliseIfDone($campaign);
    }

    /** Use the campaign's normal min/max pacing for its poll's second message. */
    private function pollPreludeDelay(Campaign $campaign): int
    {
        $min = max(1, (int) $campaign->min_delay);
        $max = max($min, (int) $campaign->max_delay);

        return random_int($min, $max);
    }

    /**
     * Campaigns created before reference IDs existed receive one on first send.
     * It is stored before delivery, so a retry uses the exact same six digits.
     */
    private function ensureReferenceId(CampaignRecipient $recipient): string
    {
        if ($recipient->variant_ref_id) {
            return $recipient->variant_ref_id;
        }

        do {
            $reference = (string) random_int(100000, 999999);
        } while (CampaignRecipient::withoutGlobalScopes()
            ->where('campaign_id', $recipient->campaign_id)
            ->where('variant_ref_id', $reference)
            ->exists());

        $recipient->update(['variant_ref_id' => $reference]);

        return $reference;
    }

    /**
     * Circuit breaker: a dropped/disconnected device freezes the WHOLE campaign.
     *
     * The current recipient is left PENDING (nothing is lost). Because handle()
     * bails out while a campaign is 'paused', every remaining queued job no-ops.
     * Reconnect the device and hit "Resume" to re-dispatch the pending recipients.
     */
    private function tripCircuitBreaker(Campaign $campaign, ?string $deviceName): void
    {
        if ($campaign->status === 'sending') {
            $campaign->update(['status' => 'paused']);

            Alert::create([
                'level'   => 'error',
                'title'   => "Campaign \"{$campaign->name}\" paused",
                'body'    => "Device \"".($deviceName ?? 'unknown')."\" is disconnected. Reconnect it and press Resume — no recipients were lost.",
                'context' => ['campaign_id' => $campaign->id],
            ]);

            Log::alert("Circuit breaker: campaign #{$campaign->id} paused — device '".($deviceName ?? 'unknown')."' is disconnected.");
        }
    }

    private function finaliseIfDone(Campaign $campaign): void
    {
        $campaign->refresh();

        if (($campaign->sent + $campaign->failed) >= $campaign->total && $campaign->status === 'sending') {
            $campaign->update(['status' => 'completed', 'completed_at' => now()]);

            DispatchWebhook::fire($campaign->tenant_id, 'campaign.completed', [
                'campaign_id' => $campaign->id,
                'name'        => $campaign->name,
                'total'       => $campaign->total,
                'sent'        => $campaign->sent,
                'failed'      => $campaign->failed,
            ]);
        }
    }

    /**
     * Pick the body to send for this recipient's assigned variant slot. The pool is
     * [main body, ...variants]; the slot cycles 0,1,2,…,0,1,2 across the campaign so
     * every consecutive message uses the next variant. Falls back to rotating by a
     * random slot only when no slot was assigned (older campaigns).
     *
     * @return array{0: int, 1: ?string}  [variant index, body]
     */
    private function variantFor(Campaign $campaign, ?int $slot): array
    {
        $pool = array_values(array_filter(
            array_merge([$campaign->body], $campaign->variants ?? []),
            fn ($v) => filled($v)
        ));

        if (empty($pool)) {
            return [0, $campaign->body];
        }

        $index = $slot !== null ? ($slot % count($pool)) : array_rand($pool);

        return [$index, $pool[$index]];
    }

    /**
     * Deliver a carousel as a sequence of image+caption cards (Baileys has no native
     * carousel endpoint). Per-card buttons are appended as text — this is the
     * "delivered with fallback text" behaviour. Each card is wrapped in try/catch so
     * one bad card never stalls the queue.
     *
     * @return array{ok: bool, message_id: ?string, error: ?string, raw: mixed}
     */
    private function sendCarousel($engine, WhatsappInstance $instance, string $number, Campaign $campaign, $contact, bool $spinRandom, string $referenceId): array
    {
        $cards = $campaign->cards ?? [];
        $anyOk = false;
        $firstId = null;
        $fallback = false;

        foreach ($cards as $card) {
            $caption = trim((! empty($card['title']) ? '*'.$card['title'].'*'."\n" : '').($card['body'] ?? ''));

            foreach (($card['buttons'] ?? []) as $b) {
                if (($b['type'] ?? '') === 'url' && ! empty($b['value'])) {
                    $caption .= "\n".($b['text'] ?? 'Link').': '.$b['value'];
                    $fallback = true;
                } elseif (! empty($b['text'])) {
                    $caption .= "\n• ".$b['text'];
                    $fallback = true;
                }
            }

            $caption = $this->personalize($caption, $contact, $number, $spinRandom, $referenceId);

            try {
                $r = ! empty($card['image'])
                    ? $engine->sendMedia($instance->instance_name, $number, 'image', $card['image'], $caption)
                    : $engine->sendText($instance->instance_name, $number, $caption);

                if ($r['ok']) {
                    $anyOk = true;
                    $firstId ??= $r['message_id'];
                } else {
                    $fallback = true;
                }
            } catch (\Throwable $e) {
                $fallback = true;
                Log::warning('Carousel card failed', ['error' => $e->getMessage()]);
            }
        }

        return [
            'ok'         => $anyOk,
            'message_id' => $firstId,
            'error'      => $anyOk ? ($fallback ? 'Delivered with fallback text only' : null) : 'All carousel cards failed',
            'raw'        => null,
        ];
    }

    /**
     * Map stored button items to the Evolution button shape.
     *
     * @return array<int, array<string, string>>
     */
    private function mapButtons(Campaign $campaign): array
    {
        return collect(data_get($campaign->buttons, 'items', []))->map(function ($b) {
            $out = ['type' => $b['type'] ?? 'reply', 'displayText' => $b['text'] ?? ''];

            if (($b['type'] ?? '') === 'url') {
                $out['url'] = $b['value'] ?? '';
            } elseif (($b['type'] ?? '') === 'call') {
                $out['phoneNumber'] = preg_replace('/\D+/', '', (string) ($b['value'] ?? ''));
            }

            return $out;
        })->all();
    }

    private function personalize(?string $body, $contact, string $number, bool $spinRandom = true, ?string $referenceId = null): string
    {
        $name = $contact?->name ?: 'there';

        // 1) Spintax variation: {Hi|Hello} -> one option (natural wording variety).
        $text = $this->spin((string) $body, $spinRandom);

        // 2) Built-in compliant merge tags — no random/tracking tokens.
        $text = preg_replace(
            ['/\{\{\s*name\s*\}\}/i', '/\{\{\s*phone\s*\}\}/i', '/\{\{\s*date\s*\}\}/i', '/\{\{\s*variant_ref_id\s*\}\}/i'],
            [$name, $number, now()->format('M j, Y'), $referenceId ?? ''],
            $text
        );

        // 3) Custom merge fields: {{anything}} resolved from the contact's own attributes.
        $attributes = (array) ($contact?->attributes ?? []);

        return preg_replace_callback('/\{\{\s*([a-z0-9_]+)\s*\}\}/i', function ($m) use ($attributes) {
            $key = strtolower($m[1]);
            foreach ($attributes as $k => $v) {
                if (strtolower((string) $k) === $key && ! is_array($v)) {
                    return (string) $v;
                }
            }

            return ''; // unknown field → blank, never a leftover {{token}}
        }, $text);
    }

    /**
     * Resolve {a|b|c} spintax. Random pick when rotation is on, else the first option.
     * Only matches single-brace groups containing a pipe, so {{name}} merge tags are untouched.
     */
    private function spin(string $text, bool $random): string
    {
        return preg_replace_callback('/\{([^{}|]*(?:\|[^{}|]*)+)\}/', function ($m) use ($random) {
            $options = explode('|', $m[1]);

            return $random ? $options[array_rand($options)] : $options[0];
        }, $text);
    }
}
