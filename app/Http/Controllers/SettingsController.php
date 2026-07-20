<?php

namespace App\Http\Controllers;

use App\Models\WhatsappInstance;
use App\Models\Contact;
use App\Services\AiService;
use App\Services\GoogleContactsService;
use App\Support\ContactCsv;
use App\Support\CronHealth;
use App\Support\MailConfig;
use App\Support\Whatsapp;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

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
            'healthChecks'  => CronHealth::checks(),
            'healthOverall' => CronHealth::overall(),
            'queueActive'   => CronHealth::engineActive(),
            'healthTasks'   => CronHealth::scheduledTasks(),
            'cronLine'      => CronHealth::cronLine(),
            'cronLastRun'   => CronHealth::lastRun(),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'whatsapp_driver'    => ['nullable', 'in:openwa'],
            'openwa_base_url'    => ['nullable', 'url', 'max:255'],
            'openwa_api_key'     => ['nullable', 'string', 'max:255'],
            'openwa_session_id'  => ['nullable', 'string', 'max:100', 'regex:/^[A-Za-z0-9_-]+$/'],
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
            'bulk_random_prefix' => ['nullable', 'string', 'max:32'],
            'bulk_spintax'       => ['sometimes', 'boolean'],
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
        $settings['bulk_random_prefix'] = trim((string) ($data['bulk_random_prefix'] ?? '')) ?: null;
        $settings['bulk_spintax']       = $request->boolean('bulk_spintax');
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
            'whatsapp_driver'    => 'openwa',
            'openwa_base_url'    => ($data['openwa_base_url'] ?? null) ?: null,
            'openwa_api_key'     => ($data['openwa_api_key'] ?? null) ?: null,
            'openwa_session_id'  => ($data['openwa_session_id'] ?? null) ?: null,
            'settings'           => $settings,
        ]);

        return redirect()->route('settings.edit')->with('success', 'Settings saved.');
    }

    /**
     * A clear per-number record of which Google account is used on the phone.
     * Native WhatsApp backups remain controlled by WhatsApp/Android; no Google
     * credentials are ever collected by Eagle on this screen.
     */
    public function googleContacts(): View
    {
        return view('settings.google-contacts', [
            'devices' => WhatsappInstance::orderBy('name')->get(),
            'callbackUrl' => rtrim((string) config('app.url'), '/').'/settings/google/callback',
            'oauthReady' => filled(data_get(auth()->user()->tenant->settings, 'google_contacts_client_id'))
                && filled(data_get(auth()->user()->tenant->settings, 'google_contacts_client_secret')),
        ]);
    }

    public function saveGoogleContactsCredentials(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'google_contacts_client_id' => ['required', 'string', 'max:255'],
            'google_contacts_client_secret' => ['nullable', 'string', 'max:255'],
        ]);
        $tenant = auth()->user()->tenant;
        $settings = $tenant->settings ?? [];
        $settings['google_contacts_client_id'] = trim($data['google_contacts_client_id']);
        if (! empty($data['google_contacts_client_secret'])) {
            $settings['google_contacts_client_secret'] = \Crypt::encryptString($data['google_contacts_client_secret']);
        }
        $tenant->update(['settings' => $settings]);

        return back()->with('success', 'Google OAuth details saved securely. You can now connect each Gmail account.');
    }

    public function connectGoogleContacts(WhatsappInstance $device, GoogleContactsService $google): RedirectResponse
    {
        $state = Str::random(48);
        Cache::put('google-contacts-oauth:'.$state, [
            'tenant_id' => auth()->user()->tenant_id, 'device_id' => $device->id, 'user_id' => auth()->id(),
        ], now()->addMinutes(10));

        try {
            return redirect()->away($google->authorizationUrl(auth()->user()->tenant, $state, $this->googleCallbackUrl()));
        } catch (\Throwable $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    public function googleContactsCallback(Request $request, GoogleContactsService $google): RedirectResponse
    {
        $state = (string) $request->query('state');
        $pending = $state ? Cache::pull('google-contacts-oauth:'.$state) : null;
        if (! $pending || $pending['user_id'] !== auth()->id() || $request->filled('error')) {
            return redirect()->route('settings.google-contacts')->with('error', 'Google connection was cancelled or expired. Please try again.');
        }
        $device = WhatsappInstance::find($pending['device_id']);
        if (! $device || $device->tenant_id !== $pending['tenant_id']) {
            abort(404);
        }
        try {
            $result = $google->connect(auth()->user()->tenant, $device, (string) $request->query('code'), $this->googleCallbackUrl());
            return redirect()->route('settings.google-contacts')->with('success', 'Google Contacts connected'.($result['email'] ? ' for '.$result['email'] : '').'.');
        } catch (\Throwable $e) {
            report($e);
            return redirect()->route('settings.google-contacts')->with('error', 'Google connection failed: '.$e->getMessage());
        }
    }

    public function updateGoogleContacts(Request $request, WhatsappInstance $device): RedirectResponse
    {
        $data = $request->validate([
            'google_contacts_email' => ['nullable', 'email:rfc,dns', 'max:255'],
        ]);

        $device->update(['google_contacts_email' => $data['google_contacts_email'] ?? null]);

        return back()->with('success', "Google account saved for {$device->name}.");
    }

    public function syncGoogleContacts(Request $request, GoogleContactsService $google): RedirectResponse
    {
        $data = $request->validate([
            'contacts_file' => ['required', 'file', 'max:10240', 'mimes:csv,txt'],
            'device_ids' => ['required', 'array', 'min:1'],
            'device_ids.*' => ['integer'],
        ]);
        try {
            $rows = ContactCsv::rows($request->file('contacts_file')->getRealPath());
        } catch (\Throwable $e) {
            return back()->with('error', $e->getMessage());
        }
        $contacts = [];
        foreach ($rows as $row) {
            $phone = ContactCsv::phone($row);
            if ($phone === '') {
                continue;
            }
            $contact = Contact::firstOrNew(['phone' => $phone]);
            $contact->name = $row['name'] ?? $row['full_name'] ?? $contact->name;
            $contact->email = $row['email'] ?? $contact->email;
            $contact->save();
            $contacts[$contact->id] = $contact;
        }
        if (! $contacts) {
            return back()->with('error', 'No valid contacts found. Your file needs a name and a number/phone column with country codes.');
        }
        $devices = WhatsappInstance::whereIn('id', $data['device_ids'])->get();
        $created = $skipped = $failed = 0;
        foreach ($devices as $device) {
            try {
                $result = $google->sync($device, array_values($contacts));
                $created += $result['created']; $skipped += $result['skipped']; $failed += $result['failed'];
            } catch (\Throwable $e) {
                report($e);
                $failed += count($contacts);
            }
        }
        return back()->with($failed ? 'error' : 'success', "Google sync complete: {$created} created, {$skipped} already synced, {$failed} failed across {$devices->count()} account(s).");
    }

    public function googleContactsSample(): StreamedResponse
    {
        return response()->streamDownload(function () {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['name', 'phone', 'email']);
            fputcsv($out, ['Ahmed Ali', '971501234567', 'ahmed@example.com']);
            fputcsv($out, ['Sara Khan', '447911123456', 'sara@example.com']);
            fclose($out);
        }, 'eagle-google-contacts-sample.csv', ['Content-Type' => 'text/csv']);
    }

    private function googleCallbackUrl(): string
    {
        return rtrim((string) config('app.url'), '/').'/settings/google/callback';
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
        $instances = WhatsappInstance::all();
        if ($instances->isEmpty()) {
            return response()->json(['ok' => false, 'message' => 'No linked WhatsApp numbers yet — add a device first.'], 422);
        }

        $secret = (string) config('whatsapp.webhook_secret');
        $url = rtrim((string) config('app.url'), '/').'/webhooks/openwa'.($secret !== '' ? '/'.$secret : '');
        $updated = 0;
        $failed = [];

        foreach ($instances as $instance) {
            try {
                Whatsapp::forInstance($instance)->setWebhook($instance->instance_name, $url);
                $updated++;
            } catch (\Throwable $e) {
                $failed[] = $instance->name;
                Log::warning('OpenWA webhook registration failed', ['instance' => $instance->instance_name, 'error' => $e->getMessage()]);
            }
        }

        return response()->json([
            'ok' => $updated > 0,
            'message' => $updated > 0
                ? "Webhook updates enabled on {$updated} device(s).".($failed ? ' Failed: '.implode(', ', $failed).'.' : '')
                : 'Could not register webhook updates. Check the OpenWA session and API connection.',
        ], $updated > 0 ? 200 : 422);

        return response()->json([
            'ok' => false,
            'message' => 'Configure OpenWA with --webhook using this app’s /webhooks/openwa endpoint. OpenWA webhook registration is set when its runtime starts.',
        ], 422);

        $instances = WhatsappInstance::all();

        if ($instances->isEmpty()) {
            return response()->json(['ok' => false, 'message' => 'No linked WhatsApp numbers yet — add a device first.'], 422);
        }

        $url = '';
        $ok = 0;
        $failed = [];

        foreach ($instances as $instance) {
            // Each number keeps the engine it was linked on (Evolution or webjs).
            // Resolve per instance — using the tenant default would push a number's
            // webhook to the wrong engine, which 404s (e.g. an Evolution number
            // hitting the webjs bridge → "Failed on: <name>").
            $engine = Whatsapp::forInstance($instance);

            if (! $engine->configured()) {
                $failed[] = $instance->name;
                continue;
            }

            try {
                $engine->setWebhook($instance->instance_name, $url);
                $ok++;
            } catch (\Throwable $e) {
                $failed[] = $instance->name;
                Log::warning('setWebhook failed', ['instance' => $instance->instance_name, 'driver' => $instance->driver, 'error' => $e->getMessage()]);
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
     * Terminal-free "Restart workers": signal the running queue worker to finish
     * its current job and exit. On the database queue this writes a cache flag
     * (no Redis needed); the systemd worker / schedule-driven queue:work then
     * relaunches with fresh code. Owner/super-admin only.
     */
    public function restartWorkers(): JsonResponse
    {
        $this->authorizeProcessControl();

        Artisan::call('queue:restart');

        return response()->json([
            'ok'      => true,
            'message' => 'Workers signalled to restart — the running worker finishes its current message, then relaunches within a few seconds.',
        ]);
    }

    /**
     * Re-queue every permanently-failed job (terminal-free `queue:retry all`).
     */
    public function retryFailedJobs(): JsonResponse
    {
        $this->authorizeProcessControl();

        Artisan::call('queue:retry', ['id' => ['all']]);

        return response()->json(['ok' => true, 'message' => 'Failed jobs re-queued — they will send on the next worker pass.']);
    }

    /**
     * Clear the failed-jobs log (terminal-free `queue:flush`).
     */
    public function flushFailedJobs(): JsonResponse
    {
        $this->authorizeProcessControl();

        Artisan::call('queue:flush');

        return response()->json(['ok' => true, 'message' => 'Failed-jobs log cleared.']);
    }

    /** Process controls are destructive-ish infra actions — owners/super-admins only. */
    private function authorizeProcessControl(): void
    {
        $user = auth()->user();

        abort_unless($user && ($user->isOwner() || $user->isSuperAdmin()), 403);
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
