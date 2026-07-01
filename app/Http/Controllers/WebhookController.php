<?php

namespace App\Http\Controllers;

use App\Jobs\DispatchWebhook;
use App\Models\CampaignRecipient;
use App\Models\Contact;
use App\Models\Message;
use App\Models\Suppression;
use App\Models\WhatsappInstance;
use App\Services\ChatbotService;
use App\Services\EvolutionApiService;
use App\Support\Tenancy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WebhookController extends Controller
{
    public function __construct(private readonly ChatbotService $chatbot)
    {
    }

    public function handle(Request $request, ?string $secret = null): JsonResponse
    {
        $expected = config('evolution.webhook_secret');
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
                'connection.update' => $this->onConnectionUpdate($instance, $data),
                'qrcode.updated'    => $this->onQrUpdated($instance, $data),
                'messages.upsert'   => $this->onMessages($instance, $data),
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
            $text = $item['message']['conversation']
                ?? data_get($item, 'message.extendedTextMessage.text')
                ?? '';

            if ($phone === '') {
                continue;
            }

            $contact = Contact::firstOrCreate(
                ['phone' => $phone],
                ['name' => $item['pushName'] ?? null]
            );

            Message::create([
                'whatsapp_instance_id' => $instance->id,
                'contact_id'           => $contact->id,
                'direction'            => $fromMe ? 'out' : 'in',
                'remote_jid'           => $remoteJid,
                'phone'                => $phone,
                'type'                 => 'text',
                'body'                 => $text,
                'status'               => 'received',
                'message_id'           => $key['id'] ?? null,
            ]);

            if (! $fromMe && $text !== '') {
                // Opt-out keywords take priority — unsubscribe + confirm, skip the auto-reply.
                if ($this->handleOptOut($instance, $contact, $text)) {
                    DispatchWebhook::fire($instance->tenant_id, 'contact.opted_out', [
                        'contact_id' => $contact->id, 'phone' => $phone, 'keyword' => trim($text),
                    ]);
                } else {
                    $this->chatbot->handleInbound($instance, $contact, $text);
                }

                $this->forwardToHook($instance, $contact, $phone, $text);

                DispatchWebhook::fire($instance->tenant_id, 'message.received', [
                    'contact_id' => $contact->id,
                    'name'       => $contact->name,
                    'phone'      => $phone,
                    'text'       => $text,
                ]);
            }
        }
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

        if (! in_array($normalized, $keywords, true)) {
            return false;
        }

        $contact->update(['opted_out' => true]);

        Suppression::updateOrCreate(
            ['tenant_id' => $instance->tenant_id, 'phone' => $contact->phone],
            ['reason' => 'Replied "'.trim($text).'"', 'source' => 'opt_out'],
        );

        $reply = data_get($instance->tenant?->settings, 'optout_reply')
            ?: "You've been unsubscribed and won't receive further messages. Reply START to opt back in.";

        EvolutionApiService::forInstance($instance)->sendText($instance->instance_name, $contact->phone, $reply);

        return true;
    }

    /**
     * Forward an inbound reply to the tenant's configured "hook" number, if set.
     */
    private function forwardToHook(WhatsappInstance $instance, Contact $contact, string $phone, string $text): void
    {
        $hook = preg_replace('/\D+/', '', (string) data_get($instance->tenant?->settings, 'bulk_hook_number', ''));

        if (! $hook || $hook === $phone) {
            return;
        }

        EvolutionApiService::forInstance($instance)->sendText(
            $instance->instance_name,
            $hook,
            "📩 Reply from ".($contact->name ?: $phone)." (+{$phone}):\n".$text
        );
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
