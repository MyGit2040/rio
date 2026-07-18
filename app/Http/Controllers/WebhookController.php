<?php

namespace App\Http\Controllers;

use App\Jobs\DispatchWebhook;
use App\Models\Campaign;
use App\Models\CampaignRecipient;
use App\Models\Contact;
use App\Models\Alert;
use App\Models\Message;
use App\Models\Suppression;
use App\Models\WhatsappInstance;
use App\Services\ChatbotService;
use App\Support\Tenancy;
use App\Support\Whatsapp;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WebhookController extends Controller
{
    public function __construct(private readonly ChatbotService $chatbot)
    {
    }

    public function handle(Request $request, ?string $secret = null): JsonResponse
    {
        $expected = config('openwa.webhook_secret');
        if ($expected && $secret !== $expected) {
            return response()->json(['ok' => false], 403);
        }

        $event = str_replace('_', '.', strtolower((string) $request->input('event')));
        $instanceName = $request->input('instance');
        $data = $request->input('data', []);

        $instance = WhatsappInstance::withoutGlobalScopes()
            ->where('instance_name', $instanceName)
            ->first();

        if (! $instance) {
            return response()->json(['ok' => true]); // unknown instance — ignore quietly
        }

        Tenancy::run($instance->tenant_id, function () use ($event, $instance, $data) {
            match ($event) {
                'connection.update', 'session.status' => $this->onConnectionUpdate($instance, $data),
                'qrcode.updated'    => $this->onQrUpdated($instance, $data),
                'messages.upsert', 'message.received' => $this->onMessages($instance, $data),
                'messages.update'   => $this->onMessageStatus($data),
                default             => null,
            };
        });

        return response()->json(['ok' => true]);
    }

    private function onConnectionUpdate(WhatsappInstance $instance, array $data): void
    {
        $state = $data['state'] ?? data_get($data, 'instance.state');

        if (! $state) {
            return;
        }

        $instance->update([
            'status'       => $state,
            'qr_code'      => $state === 'open' ? null : $instance->qr_code,
            'connected_at' => $state === 'open' ? ($instance->connected_at ?? now()) : $instance->connected_at,
        ]);
    }

    private function onQrUpdated(WhatsappInstance $instance, array $data): void
    {
        $base64 = data_get($data, 'qrcode.base64') ?? ($data['base64'] ?? null);

        if ($base64) {
            $instance->update([
                'qr_code' => str_starts_with($base64, 'data:') ? $base64 : 'data:image/png;base64,'.$base64,
                'status'  => 'connecting',
            ]);
        }
    }

    private function onMessages(WhatsappInstance $instance, array $data): void
    {
        // The payload may be a single message or a list of them.
        $items = isset($data['key']) ? [$data] : array_filter($data, 'is_array');

        foreach ($items as $item) {
            $key = $item['key'] ?? null;
            if (! $key) {
                continue;
            }

            $remoteJid = $key['remoteJid'] ?? '';

            // Skip group chats and broadcast/status.
            if (str_contains($remoteJid, '@g.us') || str_contains($remoteJid, 'broadcast')) {
                continue;
            }

            $fromMe = (bool) ($key['fromMe'] ?? false);
            $phone = preg_replace('/\D+/', '', explode('@', $remoteJid)[0] ?? '');

            if ($phone === '') {
                continue;
            }

            // What did they send — a text reply, a button/list click, or a poll answer?
            [$kind, $detail] = $this->parseInbound($item['message'] ?? []);
            if ($kind === '') {
                continue; // media / reaction / status update — nothing actionable
            }

            // Idempotency: the engine re-delivers the same messages.upsert event
            // (OpenWA and upstream transport retries can re-post). Without this guard we
            // recorded a duplicate AND re-forwarded the reply to the hook number on
            // every delivery — the "same reply every minute" bug. If we've already
            // seen this exact WhatsApp message id on this number, skip it entirely.
            $messageId = $key['id'] ?? null;
            if ($messageId && Message::where('whatsapp_instance_id', $instance->id)
                ->where('message_id', $messageId)->exists()) {
                continue;
            }

            $contact = Contact::firstOrCreate(
                ['phone' => $phone],
                ['name' => $item['pushName'] ?? null]
            );

            // Attribute the response to the campaign we last messaged this contact from.
            $campaignId = $fromMe ? null : $this->recentCampaignId($contact);

            Message::create([
                'whatsapp_instance_id' => $instance->id,
                'contact_id'           => $contact->id,
                'campaign_id'          => $campaignId,
                'direction'            => $fromMe ? 'out' : 'in',
                'remote_jid'           => $remoteJid,
                'phone'                => $phone,
                'type'                 => $kind === 'text' ? 'text' : $kind,
                'body'                 => $detail,
                'status'               => 'received',
                'message_id'           => $messageId,
            ]);

            if (! $fromMe) {
                // A text reply can be an opt-out or trigger the auto-reply bot.
                if ($kind === 'text') {
                    if ($this->handleOptOut($instance, $contact, $detail)) {
                        DispatchWebhook::fire($instance->tenant_id, 'contact.opted_out', [
                            'contact_id' => $contact->id, 'phone' => $phone, 'keyword' => trim($detail),
                        ]);
                    } else {
                        $this->chatbot->handleInbound($instance, $contact, $detail);
                    }
                }

                // Forward the full detail (who, what kind, which answer, which campaign) to the hook number.
                $this->forwardToHook($instance, $contact, $phone, $kind, $detail, $campaignId);

                if ($kind === 'poll_response') {
                    $campaignName = $campaignId ? Campaign::whereKey($campaignId)->value('name') : null;
                    $who = $contact->name ?: '+'.$phone;

                    Alert::create([
                        'level'   => 'info',
                        'title'   => 'New poll vote'.($campaignName ? " · {$campaignName}" : ''),
                        'body'    => "{$who} chose: {$detail}",
                        'context' => [
                            'campaign_id' => $campaignId,
                            'contact_id'  => $contact->id,
                            'phone'       => $phone,
                            'answer'      => $detail,
                        ],
                    ]);
                }

                DispatchWebhook::fire($instance->tenant_id, 'message.received', [
                    'contact_id'  => $contact->id,
                    'name'        => $contact->name,
                    'phone'       => $phone,
                    'kind'        => $kind,      // text | button_response | poll_response
                    'text'        => $detail,
                    'campaign_id' => $campaignId,
                ]);
            }
        }
    }

    /**
     * Classify an inbound WhatsApp message → [kind, detail].
     * kind = text | button_response | poll_response | '' (ignore).
     *
     * @param  array<string, mixed>  $m
     * @return array{0: string, 1: string}
     */
    private function parseInbound(array $m): array
    {
        $text = $m['conversation'] ?? data_get($m, 'extendedTextMessage.text');
        if (is_string($text) && $text !== '') {
            return ['text', $text];
        }

        // Buttons / template buttons / list selections.
        $btn = data_get($m, 'buttonsResponseMessage.selectedDisplayText')
            ?? data_get($m, 'buttonsResponseMessage.selectedButtonId')
            ?? data_get($m, 'templateButtonReplyMessage.selectedDisplayText')
            ?? data_get($m, 'listResponseMessage.title')
            ?? data_get($m, 'listResponseMessage.singleSelectReply.selectedRowId');
        if (is_string($btn) && $btn !== '') {
            return ['button_response', $btn];
        }

        // Poll vote (the gateway decodes the chosen option(s) when available).
        if (isset($m['pollUpdateMessage'])) {
            $opts = data_get($m, 'pollUpdateMessage.vote.selectedOptions')
                ?? data_get($m, 'pollUpdateMessage.selectedOptions')
                ?? data_get($m, 'pollUpdateMessage.pollVotes');

            $detail = '';
            if (is_array($opts)) {
                $detail = collect($opts)->map(fn ($o) => is_array($o) ? ($o['name'] ?? $o['optionName'] ?? '') : $o)->filter()->implode(', ');
            } elseif (is_string($opts)) {
                $detail = $opts;
            }

            return ['poll_response', $detail !== '' ? $detail : 'voted'];
        }

        return ['', ''];
    }

    /**
     * The campaign this contact was most recently messaged from (last 14 days).
     */
    private function recentCampaignId(Contact $contact): ?int
    {
        return Message::where('contact_id', $contact->id)
            ->where('direction', 'out')
            ->whereNotNull('campaign_id')
            ->where('created_at', '>=', now()->subDays(14))
            ->latest('id')
            ->value('campaign_id');
    }

    /**
     * If the inbound text is one of the tenant's opt-out keywords, unsubscribe the
     * contact, add them to the suppression list, and send a confirmation reply.
     */
    private function handleOptOut(WhatsappInstance $instance, Contact $contact, string $text): bool
    {
        $raw = data_get($instance->tenant?->settings, 'optout_keywords');
        $keywords = collect(preg_split('/[,\n]+/', (string) ($raw ?: 'STOP,UNSUBSCRIBE,CANCEL,END,QUIT')))
            ->map(fn ($k) => strtoupper(trim($k)))
            ->filter()
            ->all();

        $normalized = strtoupper(trim(preg_replace('/[^\p{L}\p{N}\s]/u', '', $text)));

        // Opt out on an exact keyword ("STOP") OR the keyword appearing as a whole
        // word anywhere in the reply ("please unsubscribe me") — never miss an opt-out.
        $matched = in_array($normalized, $keywords, true)
            || collect($keywords)->contains(fn ($k) => preg_match('/\b'.preg_quote($k, '/').'\b/', $normalized) === 1);

        if (! $matched) {
            return false;
        }

        $contact->update(['opted_out' => true]);

        Suppression::updateOrCreate(
            ['tenant_id' => $instance->tenant_id, 'phone' => $contact->phone],
            ['reason' => 'Replied "'.trim($text).'"', 'source' => 'opt_out'],
        );

        $reply = data_get($instance->tenant?->settings, 'optout_reply')
            ?: "You've been unsubscribed and won't receive further messages. Reply START to opt back in.";

        Whatsapp::forInstance($instance)->sendText($instance->instance_name, $contact->phone, $reply);

        return true;
    }

    /**
     * Forward a detailed inbound event to the tenant's configured "hook" number.
     * Says WHO, WHAT they did (reply / poll answer / button click), the exact answer,
     * and WHICH campaign it belongs to.
     */
    private function forwardToHook(WhatsappInstance $instance, Contact $contact, string $phone, string $kind, string $detail, ?int $campaignId): void
    {
        $hook = preg_replace('/\D+/', '', (string) data_get($instance->tenant?->settings, 'bulk_hook_number', ''));

        if (! $hook || $hook === $phone) {
            return;
        }

        $who = $contact->name ?: $phone;
        $campaignName = $campaignId ? Campaign::where('id', $campaignId)->value('name') : null;
        $ctx = $campaignName ? "\n📣 Campaign: {$campaignName}" : '';

        $body = match ($kind) {
            'poll_response'   => "📊 Poll answer from {$who} (+{$phone}):\n👉 {$detail}{$ctx}",
            'button_response' => "🔘 Button click from {$who} (+{$phone}):\n👉 {$detail}{$ctx}",
            default           => "📩 Reply from {$who} (+{$phone}):\n{$detail}{$ctx}",
        };

        Whatsapp::forInstance($instance)->sendText($instance->instance_name, $hook, $body);
    }

    private function onMessageStatus(array $data): void
    {
        $messageId = data_get($data, 'key.id') ?? ($data['keyId'] ?? null);
        $status = strtolower((string) ($data['status'] ?? ''));

        if (! $messageId || ! $status) {
            return;
        }

        $map = [
            'delivery_ack' => 'delivered',
            'read'         => 'read',
            'played'       => 'read',
        ];
        $mapped = $map[$status] ?? null;

        if (! $mapped) {
            return;
        }

        CampaignRecipient::where('message_id', $messageId)
            ->whereIn('status', ['sent', 'delivered'])
            ->update(['status' => $mapped]);
    }
}
