<?php

namespace App\Http\Controllers;

use App\Models\WhatsappInstance;
use App\Services\AiService;
use App\Services\EvolutionApiService;
use App\Support\CronHealth;
use App\Support\MailConfig;
use App\Support\Whatsapp;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\View\View;

class SettingsController extends Controller
{
    public function edit(): View
    {
        $tenant = auth()->user()->tenant;
        $engine = Whatsapp::forTenant($tenant);

        return view('settings.edit', [
            'tenant'        => $tenant,
            'engineReady'   => $engine->configured(),
            'aiEnabled'     => (bool) data_get($tenant->settings, 'ai_enabled', false),
            'platformUrl'   => config('evolution.base_url'),
            'healthChecks'  => CronHealth::checks(),
            'healthOverall' => CronHealth::overall(),
            'healthTasks'   => CronHealth::scheduledTasks(),
            'cronLine'      => CronHealth::cronLine(),
            'cronLastRun'   => CronHealth::lastRun(),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'evolution_base_url' => ['nullable', 'url', 'max:255'],
            'evolution_api_key'  => ['nullable', 'string', 'max:255'],
            // WhatsApp engine selection (Evolution / whatsapp-web.js bridge).
            'whatsapp_driver'    => ['nullable', 'in:evolution,webjs'],
            'webjs_base_url'     => ['nullable', 'url', 'max:255'],
            'webjs_api_key'      => ['nullable', 'string', 'max:255'],
            'ai_enabled'         => ['sometimes', 'boolean'],
            'brand_name'         => ['nullable', 'string', 'max:60'],
            'accent_color'       => ['nullable', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'logo'               => ['nullable', 'image', 'mimes:png,jpg,jpeg,svg,webp', 'max:2048'],
            'favicon'            => ['nullable', 'file', 'mimes:png,jpg,jpeg,svg,webp,ico', 'max:1024'],
            // Bulk-messaging safety (compliant rate-limiting only).
            'bulk_delay_min'     => ['nullable', 'integer', 'min:1', 'max:600'],
            'bulk_delay_max'     => ['nullable', 'integer', 'gte:bulk_delay_min', 'max:600'],
            'bulk_sleep_after'   => ['nullable', 'integer', 'min:0', 'max:1000'],
            'bulk_sleep_seconds' => ['nullable', 'integer', 'min:0', 'max:3600'],
            'bulk_hook_number'   => ['nullable', 'string', 'max:32'],
            'bulk_spintax'       => ['sometimes', 'boolean'],
            'allow_non_verified' => ['sometimes', 'boolean'],
            'bulk_device_failover' => ['sometimes', 'boolean'],
            // Quiet hours (compliant courtesy — delays sends, never alters content).
            'quiet_hours_enabled' => ['sometimes', 'boolean'],
            'quiet_start'         => ['nullable', 'date_format:H:i'],
            'quiet_end'           => ['nullable', 'date_format:H:i'],
            'quiet_timezone'      => ['nullable', 'timezone'],
            // Opt-out keywords + auto-reply.
            'optout_keywords'     => ['nullable', 'string', 'max:255'],
            'optout_reply'        => ['nullable', 'string', 'max:1000'],
            'smtp_host'          => ['nullable', 'string', 'max:255'],
            'smtp_port'          => ['nullable', 'integer', 'min:1', 'max:65535'],
            'smtp_user'          => ['nullable', 'string', 'max:255'],
            'smtp_pass'          => ['nullable', 'string', 'max:255'],
            'smtp_from'          => ['nullable', 'email', 'max:255'],
            'smtp_encryption'    => ['nullable', 'in:tls,ssl,none'],
            'ai_provider'        => ['nullable', 'in:openai,gemini,claude'],
            'ai_openai_key'      => ['nullable', 'string', 'max:255'],
            'ai_gemini_key'      => ['nullable', 'string', 'max:255'],
            'ai_claude_key'      => ['nullable', 'string', 'max:255'],
        ]);

        $tenant = auth()->user()->tenant;
        $settings = $tenant->settings ?? [];

        $settings['ai_enabled']   = $request->boolean('ai_enabled');
        $settings['brand_name']   = ($data['brand_name'] ?? null) ?: null;
        $settings['accent_color'] = ($data['accent_color'] ?? null) ?: ($settings['accent_color'] ?? null);

        // Bulk-messaging safety settings.
        $settings['bulk_delay_min']     = (int) ($data['bulk_delay_min'] ?? 40);
        $settings['bulk_delay_max']     = (int) ($data['bulk_delay_max'] ?? 90);
        $settings['bulk_sleep_after']   = (int) ($data['bulk_sleep_after'] ?? 0);
        $settings['bulk_sleep_seconds'] = (int) ($data['bulk_sleep_seconds'] ?? 0);
        $settings['bulk_hook_number']   = preg_replace('/\D+/', '', (string) ($data['bulk_hook_number'] ?? '')) ?: null;
        $settings['bulk_spintax']       = $request->boolean('bulk_spintax');
        $settings['allow_non_verified'] = $request->boolean('allow_non_verified');
        $settings['bulk_device_failover'] = $request->boolean('bulk_device_failover');

        // Quiet hours.
        $settings['quiet_hours_enabled'] = $request->boolean('quiet_hours_enabled');
        $settings['quiet_start']         = $data['quiet_start'] ?? '21:00';
        $settings['quiet_end']           = $data['quiet_end'] ?? '08:00';
        // Local timezone the quiet window is measured in — without this the guard
        // runs in UTC and defers sends at the wrong wall-clock time (Dubai bug).
        $settings['quiet_timezone']      = ($data['quiet_timezone'] ?? null) ?: config('app.timezone', 'UTC');

        // Opt-out handling.
        $settings['optout_keywords'] = ($data['optout_keywords'] ?? null) ?: 'STOP,UNSUBSCRIBE,CANCEL,END,QUIT';
        $settings['optout_reply']    = $data['optout_reply'] ?? null;

        // SMTP (per-workspace mail for OTP etc.). Keep existing password if left blank.
        $settings['smtp_host']       = $data['smtp_host'] ?? null;
        $settings['smtp_port']       = $data['smtp_port'] ?? null;
        $settings['smtp_user']       = $data['smtp_user'] ?? null;
        $settings['smtp_from']       = $data['smtp_from'] ?? null;
        $settings['smtp_encryption'] = $data['smtp_encryption'] ?? 'tls';
        if (! empty($data['smtp_pass'])) {
            $settings['smtp_pass'] = $data['smtp_pass'];
        }

        // AI provider + keys (keep an existing key if its field is left blank).
        $settings['ai_provider'] = $data['ai_provider'] ?? ($settings['ai_provider'] ?? 'openai');
        foreach (['ai_openai_key', 'ai_gemini_key', 'ai_claude_key'] as $k) {
            if (! empty($data[$k])) {
                $settings[$k] = $data[$k];
            }
        }

        if ($request->hasFile('logo')) {
            $settings['logo_path'] = $request->file('logo')->store("logos/{$tenant->id}", 'public');
        }

        if ($request->hasFile('favicon')) {
            $settings['favicon_path'] = $request->file('favicon')->store("favicons/{$tenant->id}", 'public');
        }

        $tenant->update([
            'evolution_base_url' => ($data['evolution_base_url'] ?? null) ?: null,
            'evolution_api_key'  => ($data['evolution_api_key'] ?? null) ?: null,
            'whatsapp_driver'    => ($data['whatsapp_driver'] ?? null) ?: 'evolution',
            'webjs_base_url'     => ($data['webjs_base_url'] ?? null) ?: null,
            'webjs_api_key'      => ($data['webjs_api_key'] ?? null) ?: null,
            'settings'           => $settings,
        ]);

        return redirect()->route('settings.edit')->with('success', 'Settings saved.');
    }

    /**
     * Send a test email using the workspace's saved SMTP settings.
     */
    public function testEmail(): JsonResponse
    {
        $tenant = auth()->user()->tenant;

        if (empty(data_get($tenant->settings, 'smtp_host')) || empty(data_get($tenant->settings, 'smtp_user'))) {
            return response()->json(['ok' => false, 'message' => 'Save your SMTP host, username and password first, then test.'], 422);
        }

        MailConfig::applyTenant($tenant);
        $to = auth()->user()->email;

        try {
            Mail::raw("This is a test email from Eagle.\n\nIf you received this, your SMTP settings are working correctly.", function ($m) use ($to) {
                $m->to($to)->subject('Eagle — SMTP test email');
            });

            return response()->json(['ok' => true, 'message' => "Test email sent to {$to}. Check your inbox (and spam folder)."]);
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'message' => 'Send failed: '.$e->getMessage()], 422);
        }
    }

    /**
     * Turn on automatic updates: (re)register this app's webhook on every linked
     * WhatsApp number so Evolution pushes delivery/read receipts and inbound
     * replies back automatically. Fixes numbers linked before a webhook was set
     * (their statuses would otherwise stay stuck on "sent").
     */
    public function syncEngineUpdates(): JsonResponse
    {
        $tenant = auth()->user()->tenant;
        $engine = Whatsapp::forTenant($tenant);

        if (! $engine->configured()) {
            return response()->json(['ok' => false, 'message' => 'Add the engine URL and API key and save first, then try again.'], 422);
        }

        $instances = WhatsappInstance::all();

        if ($instances->isEmpty()) {
            return response()->json(['ok' => false, 'message' => 'No linked WhatsApp numbers yet — add a device first.'], 422);
        }

        $url = EvolutionApiService::webhookUrl();
        $ok = 0;
        $failed = [];

        foreach ($instances as $instance) {
            try {
                $engine->setWebhook($instance->instance_name, $url);
                $ok++;
            } catch (\Throwable $e) {
                $failed[] = $instance->name;
                Log::warning('Evolution setWebhook failed', ['instance' => $instance->instance_name, 'error' => $e->getMessage()]);
            }
        }

        if ($ok === 0) {
            return response()->json([
                'ok' => false,
                'message' => 'Could not enable automatic updates. Check the engine URL and key are correct. Failed on: '.implode(', ', $failed).'.',
            ], 422);
        }

        $message = "Automatic updates enabled on {$ok} number".($ok === 1 ? '' : 's').
            '. Delivery receipts and replies will now sync automatically.';

        if ($failed !== []) {
            $message .= ' Could not update: '.implode(', ', $failed).'.';
        }

        return response()->json(['ok' => true, 'message' => $message]);
    }

    /**
     * Verify the AI key for the selected provider by making a tiny call.
     */
    public function testAi(): JsonResponse
    {
        $ai = AiService::forTenant(auth()->user()->tenant);

        if (! $ai->configured()) {
            return response()->json(['ok' => false, 'message' => 'Add a key for the selected provider and save, then test.'], 422);
        }

        $reply = $ai->generate('You are a connection test. Reply with only the word OK.', 'ping');

        return $reply !== null
            ? response()->json(['ok' => true, 'message' => $ai->providerLabel().' connected successfully.'])
            : response()->json(['ok' => false, 'message' => 'Could not reach '.$ai->providerLabel().'. Check the key is correct and has credit.'], 422);
    }
}
