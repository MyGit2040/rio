<?php

namespace App\Http\Controllers;

use App\Models\CampaignRecipient;
use App\Models\Contact;
use App\Models\Message;
use App\Models\WhatsappInstance;
use App\Services\InboundMessageRecorder;
use App\Services\WhatsappGatewayService;
use App\Support\Tenancy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class WebhookController extends Controller
{
    public function __construct(private readonly InboundMessageRecorder $recorder)
    {
    }

    public function handle(Request $request, ?string $secret = null): JsonResponse
    {
        $expected = config('whatsapp.webhook_secret');
        if ($expected && $secret !== $expected) {
            return response()->json(['ok' => false], 403);
        }

        $event = str_replace('_', '.', strtolower((string) $request->input('event')));

        // OpenWA v5 delivers { sessionId, event, payload: { message: ... } }.
        // Earlier adapters used { instance, event, data }. Accept both envelopes:
        // linked numbers created before an engine upgrade must not lose replies.
        $instanceName = $request->input('instance')
            ?? $request->input('sessionId')
            ?? $request->input('payload.sessionId');
        $data = $request->input('payload', $request->input('data', []));

        $instance = WhatsappInstance::withoutGlobalScopes()
            ->where('instance_name', $instanceName)
            ->first();

        // The gateway sends its immutable UUID in `sessionId`, whereas CRM
        // devices are stored under the gateway's session *name*. Resolve that
        // UUID once and cache it. Without this bridge every valid inbound event
        // (including poll votes) was quietly ignored as an unknown device.
        if (! $instance && is_string($instanceName) && $instanceName !== '') {
            try {
                $resolvedName = Cache::remember(
                    'whatsapp-gateway-session-name:'.$instanceName,
                    now()->addDay(),
                    fn () => WhatsappGatewayService::forTenant(null)->instanceNameForGatewaySessionId($instanceName),
                );

                if (is_string($resolvedName) && $resolvedName !== '') {
                    $instance = WhatsappInstance::withoutGlobalScopes()
                        ->where('instance_name', $resolvedName)
                        ->first();
                }
            } catch (\Throwable $exception) {
                report($exception);
            }
        }

        if (! $instance) {
            return response()->json(['ok' => true]); // unknown instance — ignore quietly
        }

        Tenancy::run($instance->tenant_id, function () use ($event, $instance, $data) {
            match ($event) {
                'connection.update', 'session.status', 'session.state.changed' => $this->onConnectionUpdate($instance, $data),
                'qrcode.updated'    => $this->onQrUpdated($instance, $data),
                'messages.upsert', 'message.received' => $this->onMessages($instance, $data),
                'messages.update'   => $this->onMessageStatus($instance, $data),
                default             => null,
            };
        });

        return response()->json(['ok' => true]);
    }

    private function onConnectionUpdate(WhatsappInstance $instance, array $data): void
    {
        $state = $data['state']
            ?? data_get($data, 'instance.state')
            ?? data_get($data, 'details.next');

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
        // The payload may be a legacy Baileys message/list or OpenWA v5's
        // { payload: { message: { id, from, body, type, ... } } } envelope.
        // Current gateway deliveries put the message directly in `data`.
        // Older gateway versions wrap it as `data.message`. Accept both shapes;
        // otherwise a perfectly valid poll vote reaches the webhook with 200 but
        // is silently skipped before it can be saved or forwarded to the hook.
        $message = $data['message']
            ?? ((isset($data['id']) && (isset($data['from']) || isset($data['chatId']))) ? $data : null);
        $items = is_array($message)
            ? [$this->normaliseOpenWaMessage($message)]
            : (isset($data['key']) ? [$data] : array_filter($data, 'is_array'));

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

            $messageId = $key['id'] ?? null;

            $contact = Contact::firstOrCreate(
                ['phone' => $phone],
                ['name' => $item['pushName'] ?? null]
            );

            // Attribute the response to the campaign we last messaged this contact from.
            $campaignId = $fromMe ? null : $this->recentCampaignId($contact);

            // Native WhatsApp poll votes arrive from a privacy @lid identifier,
            // not the recipient's phone. The gateway's vote id deliberately
            // carries the parent poll's WhatsApp id before `:vote:`. Resolve it
            // to our outbound record so the response is shown in the right
            // campaign dashboard and uses the original contact's identity.
            if (! $fromMe && $kind === 'poll_response' && $messageId && str_contains($messageId, ':vote:')) {
                $parentMessageId = strstr($messageId, ':vote:', true);
                $parent = $parentMessageId
                    ? Message::where('whatsapp_instance_id', $instance->id)
                        ->where('direction', 'out')
                        ->where('message_id', $parentMessageId)
                        ->first()
                    : null;

                if ($parent) {
                    $campaignId = $parent->campaign_id ?: $campaignId;

                    if ($parent->contact) {
                        $contact = $parent->contact;
                        $phone = $contact->phone;
                    }
                }
            }

            // Persist + side effects (opt-out, chatbot, hook forward, poll alert,
            // outbound webhook). Deduplication also lives there — the engine
            // re-delivers the same messages.upsert, and a repeat must be a no-op.
            $this->recorder->record($instance, [
                'message_id'  => $messageId,
                'remote_jid'  => $remoteJid,
                'phone'       => $phone,
                'from_me'     => $fromMe,
                'kind'        => $kind,
                'detail'      => $detail,
                'push_name'   => $item['pushName'] ?? null,
                'contact'     => $contact,
                'campaign_id' => $campaignId,
            ]);
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
        // A current OpenWA poll vote can include a human-readable `body`; inspect
        // its declared poll type first so it is never misclassified as plain text.
        if (isset($m['pollUpdateMessage']) || $this->isOpenWaPoll($m)) {
            $opts = data_get($m, 'pollUpdateMessage.vote.selectedOptions')
                ?? data_get($m, 'pollUpdateMessage.selectedOptions')
                ?? data_get($m, 'pollUpdateMessage.pollVotes')
                ?? data_get($m, 'poll.selectedOptions')
                ?? data_get($m, 'poll.optionsSelected')
                ?? data_get($m, 'pollVote.selectedOptions')
                ?? data_get($m, 'selectedOptions')
                ?? data_get($m, 'answer');

            $detail = '';
            if (is_array($opts)) {
                $detail = collect($opts)->map(fn ($o) => is_array($o) ? ($o['name'] ?? $o['optionName'] ?? '') : $o)->filter()->implode(', ');
            } elseif (is_string($opts)) {
                $detail = $opts;
            }

            return ['poll_response', $detail !== '' ? $detail : 'voted'];
        }

        $text = $m['conversation']
            ?? data_get($m, 'extendedTextMessage.text')
            ?? ($m['body'] ?? null)
            ?? ($m['caption'] ?? null);
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

    /** Convert OpenWA's current public message schema to the internal message shape. */
    private function normaliseOpenWaMessage(array $message): array
    {
        return [
            'key' => [
                'id' => $message['id'] ?? null,
                'remoteJid' => $message['from'] ?? $message['chatId'] ?? '',
                'fromMe' => (bool) ($message['fromMe'] ?? false),
            ],
            'pushName' => $message['pushName'] ?? $message['notifyName'] ?? null,
            'message' => $message,
        ];
    }

    private function isOpenWaPoll(array $message): bool
    {
        return in_array(strtolower((string) ($message['type'] ?? '')), ['poll', 'poll_vote', 'poll_response'], true)
            || isset($message['poll'], $message['pollVote'], $message['selectedOptions'], $message['answer']);
    }

    private function onMessageStatus(WhatsappInstance $instance, array $data): void
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

        // Mirror the tick onto the chat thread's own row (sent → delivered → read;
        // never downgrade a read back to delivered when receipts arrive late).
        Message::where('whatsapp_instance_id', $instance->id)
            ->where('direction', 'out')
            ->where('message_id', $messageId)
            ->whereIn('status', ['sent', 'delivered'])
            ->update(['status' => $mapped]);
    }
}
