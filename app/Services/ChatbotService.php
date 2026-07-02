<?php

namespace App\Services;

use App\Models\ChatbotRule;
use App\Models\Contact;
use App\Models\Message;
use App\Models\Tenant;
use App\Models\WhatsappInstance;
use App\Support\Whatsapp;

class ChatbotService
{
    /**
     * Evaluate the chatbot rules for an inbound message and auto-reply if one matches.
     * Must be called inside the instance's tenant context.
     */
    public function handleInbound(WhatsappInstance $instance, Contact $contact, string $text): void
    {
        $rule = ChatbotRule::where('is_active', true)
            ->where(function ($q) use ($instance) {
                $q->whereNull('whatsapp_instance_id')
                  ->orWhere('whatsapp_instance_id', $instance->id);
            })
            ->orderBy('priority')
            ->get()
            ->first(fn (ChatbotRule $rule) => $rule->matches($text));

        if (! $rule) {
            return;
        }

        $reply = $rule->use_ai
            ? $this->aiReply($text, $rule, $instance->tenant)
            : $rule->reply;

        if (! $reply) {
            return;
        }

        $engine = Whatsapp::forInstance($instance);
        $result = $engine->sendText($instance->instance_name, $contact->phone, $reply);

        Message::create([
            'whatsapp_instance_id' => $instance->id,
            'contact_id'           => $contact->id,
            'direction'            => 'out',
            'phone'                => $contact->phone,
            'type'                 => 'text',
            'body'                 => $reply,
            'status'               => $result['ok'] ? 'sent' : 'failed',
            'message_id'           => $result['message_id'],
        ]);
    }

    /**
     * Generate a reply using the WORKSPACE's own AI key (Settings → AI). Falls back
     * to the platform key, then to the rule's static reply if AI is unavailable.
     */
    private function aiReply(string $text, ChatbotRule $rule, ?Tenant $tenant): ?string
    {
        $ai = AiService::forTenant($tenant);

        if (! $ai->configured()) {
            return $rule->reply;
        }

        $system = $rule->reply ?: 'You are a helpful WhatsApp assistant. Reply concisely and politely.';

        return $ai->generate($system, $text) ?: $rule->reply;
    }
}
