<?php

namespace Tests\Feature;

use App\Services\BaileysGatewayDriver;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The driver must parse the gateway's real response shapes.
 *
 * This is the seam that keeps breaking: each side is internally correct, but
 * the driver read the gateway's fields at the wrong nesting and case. The
 * gateway wraps the instance row under `instance` and uses camelCase column
 * names (state, phoneNumber, displayName, lastQr); the driver was reading them
 * flat and snake_case, so every status read returned null — the device was
 * stuck "connecting" forever and the QR in the same response was discarded.
 *
 * These fixtures are the gateway's ACTUAL response shapes (see
 * eagleto-baileys-gateway src/api/routes/instances.routes.ts), so a divergence
 * fails here instead of on the device page.
 */
class BaileysGatewayResponseParsingTest extends TestCase
{
    private function driver(): BaileysGatewayDriver
    {
        return new BaileysGatewayDriver('http://gateway.test', 'k', 's');
    }

    /** The gateway GET /status body: the row wrapped under `instance`. */
    private function statusResponse(array $instance): array
    {
        return ['instance' => $instance, 'live' => true];
    }

    public function test_connection_state_reads_ready_as_open(): void
    {
        Http::fake([
            '*/status' => Http::response($this->statusResponse([
                'state' => 'READY',
                'phoneNumber' => '971500000000',
                'displayName' => 'Sales',
                'lastQr' => null,
            ])),
        ]);

        $result = $this->driver()->connectionState('demo-x');

        $this->assertSame('open', data_get($result, 'instance.state'));
        $this->assertSame('971500000000', data_get($result, 'instance.phone'));
        $this->assertSame('Sales', data_get($result, 'instance.profile_name'));
    }

    public function test_connection_state_maps_a_pre_ready_state_to_connecting(): void
    {
        Http::fake([
            '*/status' => Http::response($this->statusResponse([
                'state' => 'QR_REQUIRED',
                'lastQr' => '2@abc,def,ghi',
            ])),
        ]);

        $result = $this->driver()->connectionState('demo-x');

        // The whole reason the card was stuck: a non-open state must read as
        // 'connecting', never fall through to null.
        $this->assertSame('connecting', data_get($result, 'instance.state'));
    }

    public function test_connection_state_maps_terminal_states_to_close(): void
    {
        foreach (['LOGGED_OUT', 'ERROR', 'STOPPED', 'RESTRICTED', 'REPLACED'] as $terminal) {
            Http::fake(['*/status' => Http::response($this->statusResponse(['state' => $terminal]))]);

            $result = $this->driver()->connectionState('demo-x');

            $this->assertSame('close', data_get($result, 'instance.state'), "{$terminal} should map to close");
        }
    }

    public function test_connection_state_carries_the_qr_payload(): void
    {
        Http::fake([
            '*/status' => Http::response($this->statusResponse([
                'state' => 'QR_REQUIRED',
                'lastQr' => '2@abc,def,ghi',
            ])),
        ]);

        $result = $this->driver()->connectionState('demo-x');

        // The QR the device page's poll displays.
        $this->assertSame('2@abc,def,ghi', data_get($result, 'instance.qr'));
    }

    public function test_a_failed_status_read_reports_close_not_connecting(): void
    {
        Http::fake(['*/status' => Http::response(['error' => 'boom'], 500)]);

        $result = $this->driver()->connectionState('demo-x');

        // An unreachable gateway must surface as Disconnected so a dead device
        // is visible, rather than sitting on "connecting" indefinitely.
        $this->assertSame('close', data_get($result, 'instance.state'));
    }

    public function test_connect_returns_the_raw_qr_payload_for_the_canvas_renderer(): void
    {
        // /start (empty 202) then GET /qr (flat body).
        Http::fake([
            '*/start' => Http::response(['status' => 'STARTING'], 202),
            '*/qr' => Http::response([
                'instance_id' => 'demo-x',
                'status' => 'QR_REQUIRED',
                'qr_data' => '2@raw,payload,here',
                'qr_image_base64' => 'iVBORw0KGgoAAAA',
                'expires_at' => null,
            ]),
        ]);

        $result = $this->driver()->connect('demo-x');

        // The raw payload, NOT the bare PNG base64 — the canvas renderer treats
        // its input as QR text, so handing it PNG bytes draws an unscannable
        // square (the empty-looking box that was reported).
        $this->assertSame('2@raw,payload,here', data_get($result, 'qrcode.base64'));
        $this->assertSame('connecting', data_get($result, 'instance.state'));
    }
}
