<?php

namespace App\Http\Controllers;

use App\Services\EvolutionApiService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class SettingsController extends Controller
{
    public function edit(): View
    {
        $tenant = auth()->user()->tenant;
        $engine = EvolutionApiService::forTenant($tenant);

        return view('settings.edit', [
            'tenant'      => $tenant,
            'engineReady' => $engine->configured(),
            'aiEnabled'   => (bool) data_get($tenant->settings, 'ai_enabled', false),
            'platformUrl' => config('evolution.base_url'),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'evolution_base_url' => ['nullable', 'url', 'max:255'],
            'evolution_api_key'  => ['nullable', 'string', 'max:255'],
            'ai_enabled'         => ['sometimes', 'boolean'],
            'brand_name'         => ['nullable', 'string', 'max:60'],
            'accent_color'       => ['nullable', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'logo'               => ['nullable', 'image', 'mimes:png,jpg,jpeg,svg,webp', 'max:2048'],
            // Bulk-messaging safety (compliant rate-limiting only).
            'bulk_delay_min'     => ['nullable', 'integer', 'min:1', 'max:600'],
            'bulk_delay_max'     => ['nullable', 'integer', 'gte:bulk_delay_min', 'max:600'],
            'bulk_sleep_after'   => ['nullable', 'integer', 'min:0', 'max:1000'],
            'bulk_sleep_seconds' => ['nullable', 'integer', 'min:0', 'max:3600'],
            'bulk_hook_number'   => ['nullable', 'string', 'max:32'],
            'bulk_spintax'       => ['sometimes', 'boolean'],
            'allow_non_verified' => ['sometimes', 'boolean'],
            'smtp_host'          => ['nullable', 'string', 'max:255'],
            'smtp_port'          => ['nullable', 'integer', 'min:1', 'max:65535'],
            'smtp_user'          => ['nullable', 'string', 'max:255'],
            'smtp_pass'          => ['nullable', 'string', 'max:255'],
            'smtp_from'          => ['nullable', 'email', 'max:255'],
            'smtp_encryption'    => ['nullable', 'in:tls,ssl,none'],
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

        // SMTP (per-workspace mail for OTP etc.). Keep existing password if left blank.
        $settings['smtp_host']       = $data['smtp_host'] ?? null;
        $settings['smtp_port']       = $data['smtp_port'] ?? null;
        $settings['smtp_user']       = $data['smtp_user'] ?? null;
        $settings['smtp_from']       = $data['smtp_from'] ?? null;
        $settings['smtp_encryption'] = $data['smtp_encryption'] ?? 'tls';
        if (! empty($data['smtp_pass'])) {
            $settings['smtp_pass'] = $data['smtp_pass'];
        }

        if ($request->hasFile('logo')) {
            $settings['logo_path'] = $request->file('logo')->store("logos/{$tenant->id}", 'public');
        }

        $tenant->update([
            'evolution_base_url' => ($data['evolution_base_url'] ?? null) ?: null,
            'evolution_api_key'  => ($data['evolution_api_key'] ?? null) ?: null,
            'settings'           => $settings,
        ]);

        return redirect()->route('settings.edit')->with('success', 'Settings saved.');
    }
}
