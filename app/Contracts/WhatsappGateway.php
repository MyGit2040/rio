<?php

namespace App\Contracts;

/**
 * A pluggable WhatsApp engine.
 *
 * Two drivers implement this: EvolutionApiService (Baileys) and WebJsService
 * (whatsapp-web.js via the Node bridge). Callers resolve one through the
 * App\Support\Whatsapp helper and never care which engine is behind it.
 *
 * IMPORTANT: every driver MUST return the SAME array shapes so the app's
 * controllers/jobs parse them identically:
 *  - createInstance / connect  → ['qrcode' => ['base64','pairingCode'], 'hash' => ['apikey']]
 *  - connectionState           → ['instance' => ['state' => open|connecting|close]]
 *  - send*                     → ['ok' => bool, 'message_id' => ?string, 'error' => ?string, 'raw' => mixed]
 */
interface WhatsappGateway
{
    /** True when this engine has the credentials/URL it needs to run. */
    public function configured(): bool;

    // -- Instance lifecycle -------------------------------------------------

    public function createInstance(string $instanceName, ?string $webhookUrl = null, ?string $number = null): array;

    public function setWebhook(string $instanceName, string $webhookUrl): array;

    public function connect(string $instanceName, ?string $number = null): array;

    public function connectionState(string $instanceName): array;

    public function logout(string $instanceName): array;

    public function deleteInstance(string $instanceName): array;

    // -- Privacy / lookups --------------------------------------------------

    public function fetchPrivacy(string $instanceName): array;

    /** @param array<string, string> $settings */
    public function updatePrivacy(string $instanceName, array $settings): array;

    /**
     * @param  array<int, string>  $numbers
     * @return array<int, array{number?: string, jid?: string, exists?: bool}>
     */
    public function checkNumbers(string $instanceName, array $numbers): array;

    // -- Sending ------------------------------------------------------------

    public function sendText(string $instanceName, string $number, string $text, int $delay = 0): array;

    public function sendMedia(
        string $instanceName,
        string $number,
        string $mediaType,
        string $media,
        ?string $caption = null,
        ?string $fileName = null,
        int $delay = 0,
    ): array;

    /** @param array<int, string> $values */
    public function sendPoll(
        string $instanceName,
        string $number,
        string $question,
        array $values,
        int $selectableCount = 1,
        int $delay = 0,
    ): array;

    /**
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
    ): array;
}
