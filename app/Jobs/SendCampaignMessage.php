<?php

namespace App\Jobs;

use App\Models\Alert;
use App\Models\Campaign;
use App\Models\CampaignRecipient;
use App\Models\Message;
use App\Models\WhatsappInstance;
use App\Services\EvolutionApiService;
use App\Support\Tenancy;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
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
        // The device sticky-assigned to this contact (falls back to the campaign's primary).
        $instance = $recipient->instance ?: $campaign->instance;

        if (! $instance || ! $instance->isConnected()) {
            $this->tripCircuitBreaker($campaign, $instance?->name);

            return;
        }

        // Per-device daily cap: if this number has sent its allowance today,
        // defer this contact to tomorrow (stays pending — nothing is lost).
        if ($instance->atDailyCap()) {
            self::dispatch($recipient->id)->delay(now()->addDay()->startOfDay()->addMinutes(random_int(1, 90)));

            return;
        }

        $engine = EvolutionApiService::forInstance($instance);
        $contact = $recipient->contact;
        $number = $recipient->phone;
        $spinRandom = (bool) data_get($campaign->tenant?->settings, 'bulk_spintax', true);

        // Rotate across author-written message variants (A/B copy), then spin + personalize.
        $body = $this->chooseVariant($campaign);

        // A poll can't hold text/media itself, so send the message FIRST — the image with
        // the full text as its caption (bound together), or plain text — then the poll below.
        if ($campaign->type === 'poll') {
            $caption = $this->personalize($body, $contact, $number, $spinRandom);

            if ($campaign->media_url) {
                $engine->sendMedia($instance->instance_name, $number, $campaign->media_type ?: 'image', $campaign->media_url, $caption !== '' ? $caption : null);
            } elseif (trim($caption) !== '') {
                $engine->sendText($instance->instance_name, $number, $caption);
            }
        }

        $result = match ($campaign->type) {
            'media' => $engine->sendMedia(
                $instance->instance_name,
                $number,
                $campaign->media_type ?: 'image',
                $campaign->media_url,
                $this->personalize($body, $contact, $number, $spinRandom),
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
                $this->personalize($body, $contact, $number, $spinRandom),
                data_get($campaign->buttons, 'footer'),
                $this->mapButtons($campaign),
            ),
            'carousel' => $this->sendCarousel($engine, $instance, $number, $campaign, $contact, $spinRandom),
            default => $engine->sendText(
                $instance->instance_name,
                $number,
                $this->personalize($body, $contact, $number, $spinRandom),
            ),
        };

        if ($result['ok']) {
            $recipient->update([
                'status'     => 'sent',
                'message_id' => $result['message_id'],
                'sent_at'    => now(),
                'error'      => $result['error'] ?? null,   // may hold a non-fatal note (carousel fallback)
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
        }
    }

    /**
     * Pick one message body to send. Rotates across the main body + author-written
     * variants so consecutive sends use different (author-approved) copy.
     */
    private function chooseVariant(Campaign $campaign): ?string
    {
        $pool = array_values(array_filter(
            array_merge([$campaign->body], $campaign->variants ?? []),
            fn ($v) => filled($v)
        ));

        if (empty($pool)) {
            return $campaign->body;
        }

        return $pool[array_rand($pool)];
    }

    /**
     * Deliver a carousel as a sequence of image+caption cards (Baileys has no native
     * carousel endpoint). Per-card buttons are appended as text — this is the
     * "delivered with fallback text" behaviour. Each card is wrapped in try/catch so
     * one bad card never stalls the queue.
     *
     * @return array{ok: bool, message_id: ?string, error: ?string, raw: mixed}
     */
    private function sendCarousel($engine, WhatsappInstance $instance, string $number, Campaign $campaign, $contact, bool $spinRandom): array
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

            $caption = $this->personalize($caption, $contact, $number, $spinRandom);

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

    private function personalize(?string $body, $contact, string $number, bool $spinRandom = true): string
    {
        $name = $contact?->name ?: 'there';

        // 1) Spintax variation: {Hi|Hello} -> one option (natural wording variety).
        $text = $this->spin((string) $body, $spinRandom);

        // 2) Compliant merge tags only — no random/tracking tokens.
        return preg_replace(
            ['/\{\{\s*name\s*\}\}/i', '/\{\{\s*phone\s*\}\}/i', '/\{\{\s*date\s*\}\}/i'],
            [$name, $number, now()->format('M j, Y')],
            $text
        );
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
