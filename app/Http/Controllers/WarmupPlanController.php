<?php

namespace App\Http\Controllers;

use App\Models\WhatsappInstance;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class WarmupPlanController extends Controller
{
    /** Show every sending number's independent warm-up plan in one place. */
    public function index(): View
    {
        return view('warmup-plans.index', [
            'devices' => WhatsappInstance::orderBy('name')->get(),
        ]);
    }

    /** Save the gradual daily cap for one WhatsApp number. */
    public function update(Request $request, WhatsappInstance $device): RedirectResponse
    {
        $data = $request->validate([
            'daily_limit'    => ['nullable', 'integer', 'min:0', 'max:100000'],
            'warmup_enabled' => ['sometimes', 'boolean'],
            'warmup_start'   => ['nullable', 'integer', 'min:1', 'max:100000'],
            'warmup_per_day' => ['nullable', 'integer', 'min:0', 'max:100000'],
            'restart'        => ['nullable', 'boolean'],
        ]);

        $enabled = $request->boolean('warmup_enabled');
        $restart = $request->boolean('restart');

        $device->update([
            'daily_limit'       => (int) ($data['daily_limit'] ?? 0),
            'warmup_enabled'    => $enabled,
            'warmup_start'      => (int) ($data['warmup_start'] ?? 20),
            'warmup_per_day'    => (int) ($data['warmup_per_day'] ?? 10),
            // A changed plan continues its current ramp unless the user explicitly restarts it.
            'warmup_started_at' => $enabled && ($restart || ! $device->warmup_started_at)
                ? today()
                : $device->warmup_started_at,
        ]);

        return back()->with('success', 'Warm-up plan saved for '.$device->name.'.');
    }
}
