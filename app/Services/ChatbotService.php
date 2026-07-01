<?php

namespace App\Services;

use App\Models\ChatbotRule;
use App\Models\Contact;
use App\Models\Message;
use App\Models\WhatsappInstance;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

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
            ? $this->aiReply($text, $rule)
            : $rule->reply;

        if (! $reply) {
            return;
        }

        $engine = EvolutionApiService::forInstance($instance);
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
     * Generate a reply with OpenAI. Falls back to the rule's static reply if AI is unavailable.
     */
    private function aiReply(string $text, ChatbotRule $rule): ?string
    {
        $key = config('services.openai.key');

        if (! $key) {
            return $rule->reply;
        }

        try {
            $response = Http::withToken($key)
                ->timeout(30)
                ->post('https://api.openai.com/v1/chat/completions', [
                    'model' => config('services.openai.model', 'gpt-4o-mini'),
                    'messages' => [
                        [
                            'role' => 'system',
                            'content' => $rule->reply
                                ?: 'You are a helpful WhatsApp assistant. Reply concisely and politely.',
                        ],
                        ['role' => 'user', 'content' => $text],
                    ],
                    'max_tokens' => 300,
                ]);

            if ($response->successful()) {
                return trim((string) data_get($response->json(), 'choices.0.message.content'))
                    ?: $rule->reply;
            }

            Log::warning('OpenAI reply failed', ['status' => $response->status()]);
        } catch (\Throwable $e) {
            Log::error('OpenAI request error', ['error' => $e->getMessage()]);
        }

        return $rule->reply;
    }
}
