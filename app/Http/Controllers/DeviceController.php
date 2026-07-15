<?php

namespace App\Http\Controllers;

use App\Models\WhatsappInstance;
use App\Services\EvolutionApiService;
use App\Services\PlanLimit;
use App\Support\Audit;
use App\Support\Whatsapp;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\View\View;

class DeviceController extends Controller
{
    public function index(): View
    {
        $devices = WhatsappInstance::latest()->get();
        $engine = Whatsapp::forTenant(auth()->user()->tenant);

        return view('devices.index', [
            'devices'        => $devices,
            'engineReady'    => $engine->configured(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name'             => ['required', 'string', 'max:255'],
            'daily_limit'      => ['nullable', 'integer', 'min:0', 'max:100000'],
            'phone_for_pairing' => ['nullable', 'string', 'max:32'],
        ]);

        $tenant = auth()->user()->tenant;

        if (PlanLimit::for($tenant)->reached('devices')) {
            return back()->with('error', 'You have reached your plan\'s device limit. Upgrade in Billing to add more numbers.');
        }

        $driver = Whatsapp::driverForTenant($tenant);
        $engine = Whatsapp::forTenant($tenant);

        if (! $engine->configured()) {
            return back()->with('error', 'Connect your WhatsApp engine ('.$driver.') in Settings before adding a device.');
        }

        $instanceName = $driver === 'openwa'
            ? (string) $tenant->openwa_session_id
            : Str::lower($tenant->slug.'-'.Str::random(8));

        if ($driver === 'openwa' && WhatsappInstance::where('tenant_id', $tenant->id)->where('driver', 'openwa')->where('instance_name', $instanceName)->exists()) {
            return back()->with('error', 'This OpenWA session is already linked to a device.');
        }

        // Snapshot the engine this device is created on — it keeps using it even
        // if the tenant later flips the default driver.
        $device = WhatsappInstance::create([
            'name'          => $data['name'],
            'instance_name' => $instanceName,
            'driver'        => $driver,
            'status'        => 'connecting',
            'daily_limit'   => (int) ($data['daily_limit'] ?? 0),
        ]);

        $engine = Whatsapp::forInstance($device);

        $pairingNumber = preg_replace('/\D+/', '', (string) ($data['phone_for_pairing'] ?? '')) ?: null;

        try {
            $response = $engine->createInstance($instanceName, $this->webhookUrl(), $pairingNumber);

            $device->update([
                'token'        => data_get($response, 'hash.apikey') ?? data_get($response, 'hash') ?? null,
                'qr_code'      => $this->extractQr($response),
                'pairing_code' => $this->extractPairing($response),
                'status'       => 'connecting',
            ]);
        } catch (\Throwable $e) {
            Log::error('Evolution createInstance failed', ['error' => $e->getMessage()]);
            $device->delete();

            return back()->with('error', 'Could not reach the WhatsApp engine: '.$e->getMessage());
        }

        Audit::log('device.created', $device, $device->name);

        return redirect()->route('devices.index')
            ->with('success', 'Device created. Scan the QR code with WhatsApp to link it.');
    }

    /**
     * Request a fresh QR for an existing device.
     */
    public function connect(Request $request, WhatsappInstance $device): JsonResponse
    {
        $engine = Whatsapp::forInstance($device);

        // A phone number requests an 8-digit pairing code; without it we just refresh the QR.
        $number = preg_replace('/\D+/', '', (string) $request->input('number')) ?: null;

        try {
            $response = $engine->connect($device->instance_name, $number);
            $qr = $this->extractQr($response);
            $pairing = $this->extractPairing($response);

            $device->update([
                'qr_code'      => $qr ?: $device->qr_code,
                // Keep an existing code when a plain QR refresh returns none.
                'pairing_code' => $pairing ?: $device->pairing_code,
                'status'       => 'connecting',
            ]);

            return response()->json(['ok' => true, 'qr' => $qr, 'pairing' => $pairing]);
        } catch (\Throwable $e) {
            Log::error('Evolution connect failed', ['error' => $e->getMessage()]);

            return response()->json(['ok' => false, 'error' => $e->getMessage()], 422);
        }
    }

    /**
     * Poll the engine for connection status (used by the QR screen).
     */
    public function state(WhatsappInstance $device): JsonResponse
    {
        $engine = Whatsapp::forInstance($device);
        $response = $engine->connectionState($device->instance_name);
        $state = data_get($response, 'instance.state', $device->status);

        $device->update([
            'status'       => $state,
            'qr_code'      => $state === 'open' ? null : $device->qr_code,
            'connected_at' => $state === 'open' ? ($device->connected_at ?? now()) : $device->connected_at,
        ]);

        return response()->json([
            'ok'     => true,
            'status' => $state,
        ]);
    }

    public function show(WhatsappInstance $device): View
    {
        $engine = Whatsapp::forInstance($device);
        $privacy = [];

        if ($engine->configured() && $device->isConnected()) {
            try {
                $privacy = $engine->fetchPrivacy($device->instance_name);
            } catch (\Throwable $e) {
                Log::warning('fetchPrivacy failed', ['error' => $e->getMessage()]);
            }
        }

        return view('devices.show', compact('device', 'privacy'));
    }

    public function update(Request $request, WhatsappInstance $device): RedirectResponse
    {
        $data = $request->validate([
            'name'           => ['required', 'string', 'max:255'],
            'daily_limit'    => ['nullable', 'integer', 'min:0', 'max:100000'],
            'warmup_enabled' => ['sometimes', 'boolean'],
            'warmup_start'   => ['nullable', 'integer', 'min:1', 'max:100000'],
            'warmup_per_day' => ['nullable', 'integer', 'min:0', 'max:100000'],
        ]);

        $warmup = $request->boolean('warmup_enabled');

        $device->update([
            'name'              => $data['name'],
            'daily_limit'       => (int) ($data['daily_limit'] ?? 0),
            'warmup_enabled'    => $warmup,
            'warmup_start'      => (int) ($data['warmup_start'] ?? 20),
            'warmup_per_day'    => (int) ($data['warmup_per_day'] ?? 20),
            // Stamp the ramp start the first time warm-up is switched on.
            'warmup_started_at' => $warmup ? ($device->warmup_started_at ?? today()) : $device->warmup_started_at,
        ]);

        return back()->with('success', 'Device settings saved.');
    }

    public function updatePrivacy(Request $request, WhatsappInstance $device): RedirectResponse
    {
        $data = $request->validate([
            'last'         => ['required', 'in:all,contacts,none'],
            'profile'      => ['required', 'in:all,contacts,none'],
            'status'       => ['required', 'in:all,contacts,none'],
            'readreceipts' => ['required', 'in:all,none'],
            'online'       => ['required', 'in:all,match_last_seen'],
        ]);

        $engine = Whatsapp::forInstance($device);

        if (! $engine->configured() || ! $device->isConnected()) {
            return back()->with('error', 'The device must be connected to change privacy.');
        }

        $result = $engine->updatePrivacy($device->instance_name, $data);

        return $result['ok']
            ? back()->with('success', 'Privacy settings updated.')
            : back()->with('error', 'Could not update privacy: '.($result['error'] ?? 'unknown error'));
    }

    public function destroy(WhatsappInstance $device): RedirectResponse
    {
        $engine = Whatsapp::forInstance($device);

        try {
            $engine->logout($device->instance_name);
            $engine->deleteInstance($device->instance_name);
        } catch (\Throwable $e) {
            Log::warning('Evolution delete failed (continuing)', ['error' => $e->getMessage()]);
        }

        Audit::log('device.deleted', $device, $device->name);
        $device->delete();

        return redirect()->route('devices.index')->with('success', 'Device removed.');
    }

    private function webhookUrl(): string
    {
        return EvolutionApiService::webhookUrl();
    }

    private function extractPairing(array $response): ?string
    {
        $code = data_get($response, 'qrcode.pairingCode') ?? data_get($response, 'pairingCode') ?? null;

        return $code ?: null;
    }

    private function extractQr(array $response): ?string
    {
        $base64 = data_get($response, 'qrcode.base64')
            ?? data_get($response, 'base64')
            ?? null;

        if (! $base64) {
            return null;
        }

        return Str::startsWith($base64, 'data:') ? $base64 : 'data:image/png;base64,'.$base64;
    }
}
