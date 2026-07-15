<?php

namespace App\Services;

use App\Contracts\WhatsappGateway;
use App\Models\Tenant;
use App\Models\WhatsappInstance;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Thin wrapper around the Evolution API REST engine.
 *
 * This is the ONLY place in the app that talks to Evolution. Everything goes
 * through the global API key + the per-instance name on the Evolution server.
 */
class EvolutionApiService implements WhatsappGateway
{
    public function __construct(
        protected string $baseUrl,
        protected string $apiKey,
    ) {
    }

    public static function default(): static
    {
        return new static(
            rtrim((string) config('evolution.base_url'), '/'),
            (string) config('evolution.api_key'),
        );
    }

    public static function forTenant(?Tenant $tenant): static
    {
        return new static(
            rtrim((string) ($tenant?->evolution_base_url ?: config('evolution.base_url')), '/'),
            (string) ($tenant?->evolution_api_key ?: config('evolution.api_key')),
        );
    }

    public static function forInstance(WhatsappInstance $instance): static
    {
        return static::forTenant($instance->tenant);
    }

    public function configured(): bool
    {
        return $this->baseUrl !== '' && $this->apiKey !== '';
    }

    protected function http(): PendingRequest
    {
        return Http::baseUrl($this->baseUrl)
            ->withHeaders(['apikey' => $this->apiKey])
            ->acceptJson()
            ->timeout(30)
            ->connectTimeout(10);
    }

    // ----------------------------------------------------------------------
    // Instance lifecycle
    // ----------------------------------------------------------------------

    public function createInstance(string $instanceName, ?string $webhookUrl = null, ?string $number = null): array
    {
        $payload = [
            'instanceName' => $instanceName,
            'integration'  => config('evolution.integration', 'WHATSAPP-BAILEYS'),
            'qrcode'       => true,
        ];

        // Supplying a number makes Evolution return a pairing code (link-by-code).
        if ($number) {
            $payload['number'] = $number;
        }

        if ($webhookUrl) {
            $payload['webhook'] = [
                'enabled' => true,
                'url'     => $webhookUrl,
                'byEvents' => false,
                'base64'  => true,
                'events'  => config('evolution.webhook_events'),
            ];
        }

        return $this->http()->post('/instance/create', $payload)->throw()->json() ?? [];
    }

    /**
     * (Re)register the webhook on an EXISTING instance so status updates
     * (delivered/read receipts) and inbound replies are pushed back to us
     * automatically. Instances created before a webhook was set never receive
     * updates until this runs — statuses stay stuck on "sent".
     */
    public function setWebhook(string $instanceName, string $webhookUrl): array
    {
        return $this->http()->post("/webhook/set/{$instanceName}", [
            'webhook' => [
                'enabled'  => true,
                'url'      => $webhookUrl,
                'byEvents' => false,
                'base64'   => true,
                'events'   => config('evolution.webhook_events'),
            ],
        ])->throw()->json() ?? [];
    }

    /**
     * The public URL Evolution posts updates to for this app (with the shared
     * secret when configured). Single source of truth for both instance
     * creation and the "receive updates" re-sync.
     */
    public static function webhookUrl(): string
    {
        $secret = config('evolution.webhook_secret');

        return $secret
            ? route('webhooks.evolution', ['secret' => $secret])
            : route('webhooks.evolution');
    }

    /**
     * Ask the engine for a fresh QR code to link a phone.
     */
    public function connect(string $instanceName, ?string $number = null): array
    {
        $url = "/instance/connect/{$instanceName}";
        if ($number) {
            $url .= '?number='.urlencode($number);
        }

        return $this->http()->get($url)->throw()->json() ?? [];
    }

    public function connectionState(string $instanceName): array
    {
        return $this->http()->get("/instance/connectionState/{$instanceName}")->json() ?? [];
    }

    public function logout(string $instanceName): array
    {
        return $this->http()->delete("/instance/logout/{$instanceName}")->json() ?? [];
    }

    public function deleteInstance(string $instanceName): array
    {
        return $this->http()->delete("/instance/delete/{$instanceName}")->json() ?? [];
    }

    public function fetchPrivacy(string $instanceName): array
    {
        return $this->http()->get("/chat/fetchPrivacySettings/{$instanceName}")->json() ?? [];
    }

    /**
     * @param  array<string, string>  $settings  keys: readreceipts, profile, status, online, last, groupadd
     */
    public function updatePrivacy(string $instanceName, array $settings): array
    {
        return $this->result($this->http()->post("/chat/updatePrivacySettings/{$instanceName}", $settings));
    }

    /**
     * Check which of the given numbers actually exist on WhatsApp.
     *
     * @param  array<int, string>  $numbers
     * @return array<int, array{number?: string, jid?: string, exists?: bool}>
     */
    public function checkNumbers(string $instanceName, array $numbers): array
    {
        return $this->http()
            ->post("/chat/whatsappNumbers/{$instanceName}", ['numbers' => array_values($numbers)])
            ->throw()
            ->json() ?? [];
    }

    // ----------------------------------------------------------------------
    // Sending
    // ----------------------------------------------------------------------

    public function sendText(string $instanceName, string $number, string $text, int $delay = 0): array
    {
        $body = ['number' => $number, 'text' => $text];

        if ($delay > 0) {
            $body['delay'] = $delay;
        }

        return $this->result($this->http()->post("/message/sendText/{$instanceName}", $body));
    }

    public function sendMedia(
        string $instanceName,
        string $number,
        string $mediaType,
        string $media,
        ?string $caption = null,
        ?string $fileName = null,
        int $delay = 0,
    ): array {
        $body = array_filter([
            'number'    => $number,
            'mediatype' => $mediaType,
            'media'     => $media,
            'caption'   => $caption,
            'fileName'  => $fileName,
            'delay'     => $delay > 0 ? $delay : null,
        ], fn ($v) => $v !== null);

        return $this->result($this->http()->post("/message/sendMedia/{$instanceName}", $body));
    }

    /**
     * @param  array<int, string>  $values
     */
    public function sendPoll(
        string $instanceName,
        string $number,
        string $question,
        array $values,
        int $selectableCount = 1,
        int $delay = 0,
    ): array {
        $body = [
            'number'          => $number,
            'name'            => $question,
            'selectableCount' => max(1, $selectableCount),
            'values'          => array_values($values),
        ];

        if ($delay > 0) {
            $body['delay'] = $delay;
        }

        return $this->result($this->http()->post("/message/sendPoll/{$instanceName}", $body));
    }

    /**
     * Send an interactive buttons message.
     *
     * @param  array<int, array{type:string, displayText:string, url?:string, phoneNumber?:string}>  $buttons
     */
    public function sendButtons(
        string $instanceName,
        string $number,
        string $title,
        ?string $description,
        ?string $footer,
        array $buttons,
        int $delay = 0,
    ): array {
        $body = array_filter([
            'number'      => $number,
            'title'       => $title,
            'description' => $description,
            'footer'      => $footer,
            'buttons'     => array_values($buttons),
            'delay'       => $delay > 0 ? $delay : null,
        ], fn ($v) => $v !== null && $v !== '');

        return $this->result($this->http()->post("/message/sendButtons/{$instanceName}", $body));
    }

    // ----------------------------------------------------------------------
    // Helpers
    // ----------------------------------------------------------------------

    /**
     * Normalise a send response into a consistent shape for the queue jobs.
     *
     * @return array{ok: bool, message_id: ?string, error: ?string, raw: mixed}
     */
    protected function result(Response $response): array
    {
        $json = $response->json();

        if ($response->successful()) {
            return [
                'ok'         => true,
                'message_id' => $json['key']['id'] ?? ($json['messageId'] ?? null),
                'error'      => null,
                'raw'        => $json,
                'status'     => $response->status(),
            ];
        }

        $error = is_array($json)
            ? json_encode($json['message'] ?? $json['response'] ?? $json)
            : $response->body();

        Log::warning('Evolution send failed', [
            'status' => $response->status(),
            'error'  => $error,
        ]);

        return [
            'ok'         => false,
            'message_id' => null,
            'error'      => mb_substr((string) $error, 0, 1000),
            'raw'        => $json,
            'status'     => $response->status(),
        ];
    }
}
