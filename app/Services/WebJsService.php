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
 * Driver for the whatsapp-web.js Node bridge (Puppeteer/WhatsApp Web).
 *
 * Runs ALONGSIDE EvolutionApiService as a selectable engine. Every method
 * returns the SAME array shapes Evolution does (qrcode.base64, instance.state,
 * key.id via result()), so DeviceController / SendCampaignMessage and the
 * inbound WebhookController stay engine-agnostic.
 *
 * The bridge owns one whatsapp-web.js Client per instanceName and posts inbound
 * events back to the shared webhooks.evolution endpoint in Baileys shape — so
 * the compliance path (opt-out, suppression, chatbot) is identical to Evolution.
 */
class WebJsService implements WhatsappGateway
{
    public function __construct(
        protected string $baseUrl,
        protected string $apiKey,
    ) {
    }

    public static function default(): static
    {
        return new static(
            rtrim((string) config('webjs.base_url'), '/'),
            (string) config('webjs.api_key'),
        );
    }

    public static function forTenant(?Tenant $tenant): static
    {
        return new static(
            rtrim((string) ($tenant?->webjs_base_url ?: config('webjs.base_url')), '/'),
            (string) ($tenant?->webjs_api_key ?: config('webjs.api_key')),
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
            ->withHeaders(['X-Api-Key' => $this->apiKey])
            ->acceptJson()
            ->timeout(60)          // Puppeteer sends can be slower than Baileys
            ->connectTimeout(10);
    }

    // ----------------------------------------------------------------------
    // Instance lifecycle
    // ----------------------------------------------------------------------

    public function createInstance(string $instanceName, ?string $webhookUrl = null, ?string $number = null): array
    {
        $json = $this->http()->post('/instances', array_filter([
            'instanceName' => $instanceName,
            'webhookUrl'   => $webhookUrl,
            'number'       => $number, // present → bridge requests a pairing code
        ], fn ($v) => $v !== null))->throw()->json() ?? [];

        return $this->linkShape($json);
    }

    public function setWebhook(string $instanceName, string $webhookUrl): array
    {
        return $this->http()
            ->post("/instances/{$instanceName}/webhook", ['webhookUrl' => $webhookUrl])
            ->throw()->json() ?? [];
    }

    public function connect(string $instanceName, ?string $number = null): array
    {
        $json = $this->http()
            ->post("/instances/{$instanceName}/connect", array_filter(['number' => $number]))
            ->throw()->json() ?? [];

        return $this->linkShape($json);
    }

    public function connectionState(string $instanceName): array
    {
        $json = $this->http()->get("/instances/{$instanceName}/state")->json() ?? [];

        // Normalise to Evolution's { instance: { state } } so callers read data_get('instance.state').
        return ['instance' => ['state' => $json['state'] ?? 'close']];
    }

    public function logout(string $instanceName): array
    {
        return $this->http()->delete("/instances/{$instanceName}/logout")->json() ?? [];
    }

    public function deleteInstance(string $instanceName): array
    {
        return $this->http()->delete("/instances/{$instanceName}")->json() ?? [];
    }

    public function fetchPrivacy(string $instanceName): array
    {
        return $this->http()->get("/instances/{$instanceName}/privacy")->json() ?? [];
    }

    public function updatePrivacy(string $instanceName, array $settings): array
    {
        return $this->result($this->http()->post("/instances/{$instanceName}/privacy", $settings));
    }

    public function checkNumbers(string $instanceName, array $numbers): array
    {
        return $this->http()
            ->post("/instances/{$instanceName}/check-numbers", ['numbers' => array_values($numbers)])
            ->throw()->json() ?? [];
    }

    // ----------------------------------------------------------------------
    // Sending
    // ----------------------------------------------------------------------

    public function sendText(string $instanceName, string $number, string $text, int $delay = 0): array
    {
        return $this->result($this->http()->post("/instances/{$instanceName}/send/text", array_filter([
            'number' => $number,
            'text'   => $text,
            'delay'  => $delay > 0 ? $delay : null,
        ], fn ($v) => $v !== null)));
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
        return $this->result($this->http()->post("/instances/{$instanceName}/send/media", array_filter([
            'number'    => $number,
            'mediatype' => $mediaType,
            'media'     => $media,
            'caption'   => $caption,
            'fileName'  => $fileName,
            'delay'     => $delay > 0 ? $delay : null,
        ], fn ($v) => $v !== null)));
    }

    public function sendPoll(
        string $instanceName,
        string $number,
        string $question,
        array $values,
        int $selectableCount = 1,
        int $delay = 0,
    ): array {
        return $this->result($this->http()->post("/instances/{$instanceName}/send/poll", array_filter([
            'number'          => $number,
            'name'            => $question,
            'values'          => array_values($values),
            'selectableCount' => max(1, $selectableCount),
            'delay'           => $delay > 0 ? $delay : null,
        ], fn ($v) => $v !== null)));
    }

    public function sendButtons(
        string $instanceName,
        string $number,
        string $title,
        ?string $description,
        ?string $footer,
        array $buttons,
        int $delay = 0,
    ): array {
        // whatsapp-web.js has no reliable native buttons; the bridge falls back to a
        // text render. We still expose the method so the driver is fully swappable.
        return $this->result($this->http()->post("/instances/{$instanceName}/send/buttons", array_filter([
            'number'      => $number,
            'title'       => $title,
            'description' => $description,
            'footer'      => $footer,
            'buttons'     => array_values($buttons),
            'delay'       => $delay > 0 ? $delay : null,
        ], fn ($v) => $v !== null && $v !== '')));
    }

    // ----------------------------------------------------------------------
    // Helpers
    // ----------------------------------------------------------------------

    /**
     * Normalise the bridge's { qr, pairingCode } into Evolution's link shape so
     * DeviceController::extractQr / extractPairing work unchanged.
     */
    protected function linkShape(array $json): array
    {
        return [
            'hash'    => ['apikey' => $json['token'] ?? null],
            'qrcode'  => [
                'base64'      => $json['qr'] ?? null,
                'pairingCode' => $json['pairingCode'] ?? null,
            ],
            'instance' => ['state' => $json['status'] ?? 'connecting'],
        ];
    }

    /**
     * Normalise a send response into the shape the queue jobs expect.
     *
     * @return array{ok: bool, message_id: ?string, error: ?string, raw: mixed}
     */
    protected function result(Response $response): array
    {
        $json = $response->json();

        if ($response->successful() && ($json['success'] ?? $json['ok'] ?? true)) {
            return [
                'ok'         => true,
                'message_id' => $json['id'] ?? data_get($json, 'key.id') ?? ($json['messageId'] ?? null),
                'error'      => null,
                'raw'        => $json,
            ];
        }

        $error = is_array($json)
            ? json_encode($json['error'] ?? $json['message'] ?? $json)
            : $response->body();

        Log::warning('WebJs send failed', [
            'status' => $response->status(),
            'error'  => $error,
        ]);

        return [
            'ok'         => false,
            'message_id' => null,
            'error'      => mb_substr((string) $error, 0, 1000),
            'raw'        => $json,
        ];
    }
}
