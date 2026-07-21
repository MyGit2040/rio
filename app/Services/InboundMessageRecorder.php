<?php

namespace App\Services;

use App\Jobs\DispatchWebhook;
use App\Models\Alert;
use App\Models\Campaign;
use App\Models\Contact;
use App\Models\Message;
use App\Models\Suppression;
use App\Models\WhatsappInstance;
use App\Support\ChatRealtime;
use App\Support\Whatsapp;
use Illuminate\Support\Facades\Log;

/**
 * The single inbound-message pipeline, shared by BOTH webhook engines.
 *
 * Everything that must happen once a message reaches the app lives here:
 * idempotency, contact upsert, campaign attribution, persisting the Message row,
 * opt-out keywords, the auto-reply bot, hook-number forwarding, poll-vote alerts
 * and the tenant's outbound webhook event. The OpenWA and Baileys webhook
 * controllers keep only their own payload PARSING — before this service existed
 * the Baileys endpoint had no persistence at all, so inbound replies on Baileys
 * devices never reached the Inbox, campaign Responses, opt-out handling or the
 * contact-graph protection (which looks for direction='in' rows).
 *
 * Must be called inside the instance's tenant context (Tenancy::run / auth).
 */
class InboundMessageRecorder
{
    public function __construct(private readonly ChatbotService $chatbot)
    {
    }

    /**
     * Record one message and run the inbound side effects.
     *
     * $m keys:
     *  - message_id  ?string  WhatsApp message id (dedup key per device)
     *  - remote_jid  ?string
     *  - phone        string  digits only
     *  - from_me      bool
     *  - kind         string  text|button_response|poll_response|image|video|audio|document|…
     *  - detail       string  message body / caption / chosen answer
     *  - push_name   ?string
     *  - contact     ?Contact pre-resolved (poll votes resolve via the parent poll)
     *  - campaign_id  int|null — pass the KEY only when already resolved by the caller
     *
     * Returns the stored Message, or null when this delivery is a duplicate.
     */
    public function record(WhatsappInstance $instance, array $m): ?Message
    {
        $phone = (string) ($m['phone'] ?? '');

        if ($phone === '') {
            return null;
        }

        // Idempotency: engines re-deliver (at-least-once transport). The same
        // WhatsApp message id on the same device is processed exactly once.
        $messageId = $m['message_id'] ?? null;
        if ($messageId && Message::where('whatsapp_instance_id', $instance->id)
            ->where('message_id', $messageId)->exists()) {
            return null;
        }

        $fromMe = (bool) ($m['from_me'] ?? false);
        $kind = (string) ($m['kind'] ?? 'text');
        $detail = (string) ($m['detail'] ?? '');

        $contact = $m['contact'] ?? Contact::firstOrCreate(
            ['phone' => $phone],
            ['name' => $m['push_name'] ?? null],
        );

        // Attribute the response to the campaign we last messaged this contact
        // from — unless the caller already resolved it (poll-vote parents).
        $campaignId = $fromMe
            ? null
            : (array_key_exists('campaign_id', $m) ? $m['campaign_id'] : $this->recentCampaignId($contact));

        $message = Message::create([
            'whatsapp_instance_id' => $instance->id,
            'contact_id'           => $contact->id,
            'campaign_id'          => $campaignId,
            'direction'            => $fromMe ? 'out' : 'in',
            'remote_jid'           => $m['remote_jid'] ?? null,
            'phone'                => $phone,
            'type'                 => $kind === 'text' ? 'text' : $kind,
            'body'                 => $detail,
            'status'               => 'received',
            'message_id'           => $messageId,
        ]);

        // Push to every open Chats tab (best-effort; polling remains the floor).
        ChatRealtime::messageStored($message, $contact->name);

        if ($fromMe) {
            return $message;
        }

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
            'kind'        => $kind,      // text | button_response | poll_response | image | …
            'text'        => $detail,
            'campaign_id' => $campaignId,
        ]);

        return $message;
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

        $result = Whatsapp::forInstance($instance)->sendText($instance->instance_name, $hook, $body);

        // Do not silently claim a hook notification worked when the engine rejected
        // it. The activity panel remains useful and the server log has diagnostics.
        if (! ($result['ok'] ?? false)) {
            Log::warning('Hook notification failed', [
                'instance_id' => $instance->id,
                'kind' => $kind,
                'error' => $result['error'] ?? 'unknown error',
            ]);
            Alert::create([
                'level' => 'warning',
                'title' => 'Hook notification could not be sent',
                'body' => 'The incoming '.str_replace('_', ' ', $kind).' was saved, but forwarding it to the hook number failed. Reconnect this sending number and try again.',
                'context' => ['instance_id' => $instance->id, 'contact_id' => $contact->id, 'kind' => $kind],
            ]);
        }
    }
}
