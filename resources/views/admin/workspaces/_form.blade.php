@php
    $ws = $workspace ?? null;
    $selectedModules = collect(old('modules', $ws ? ($ws->enabled_modules ?? array_keys($modules)) : array_keys($modules)));
@endphp

<div class="space-y-6">
    <div class="bg-white rounded-xl border border-gray-200 shadow-sm p-5">
        <h3 class="font-semibold text-gray-800 mb-4">Workspace &amp; owner login</h3>
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <div>
                <label class="block text-sm text-gray-600 mb-1">Workspace / client name</label>
                <input name="name" value="{{ old('name', $ws->name ?? '') }}" required
                       class="block w-full rounded-lg border-gray-300 text-sm focus:ring-brand focus:border-brand">
            </div>
            <div>
                <label class="block text-sm text-gray-600 mb-1">Owner name</label>
                <input name="owner_name" value="{{ old('owner_name', $owner->name ?? '') }}" {{ isset($owner) ? '' : 'required' }}
                       class="block w-full rounded-lg border-gray-300 text-sm focus:ring-brand focus:border-brand">
            </div>
            <div>
                <label class="block text-sm text-gray-600 mb-1">Owner email (login)</label>
                @if (isset($owner))
                    <input value="{{ $owner->email }}" disabled class="block w-full rounded-lg border-gray-200 bg-gray-50 text-sm text-gray-500">
                @else
                    <input name="owner_email" type="email" value="{{ old('owner_email') }}" required
                           class="block w-full rounded-lg border-gray-300 text-sm focus:ring-brand focus:border-brand">
                @endif
            </div>
            <div>
                <label class="block text-sm text-gray-600 mb-1">{{ isset($owner) ? 'Reset password (optional)' : 'Password' }}</label>
                <input name="password" type="text" {{ isset($owner) ? '' : 'required' }} placeholder="{{ isset($owner) ? 'leave blank to keep' : 'min 8 characters' }}"
                       class="block w-full rounded-lg border-gray-300 text-sm focus:ring-brand focus:border-brand">
            </div>
        </div>
    </div>

    <div class="bg-white rounded-xl border border-gray-200 shadow-sm p-5">
        <h3 class="font-semibold text-gray-800 mb-4">Subscription</h3>
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <div>
                <label class="block text-sm text-gray-600 mb-1">Plan type</label>
                <select name="plan_type" class="block w-full rounded-lg border-gray-300 text-sm focus:ring-brand focus:border-brand">
                    @if (isset($owner))<option value="">Keep current ({{ $ws->expires_at?->format('M j, Y') ?? 'Never' }})</option>@endif
                    @foreach ($planTypes as $key => $pt)
                        <option value="{{ $key }}" @selected(old('plan_type', isset($owner) ? '' : 'monthly') === $key)>{{ $pt['label'] }}</option>
                    @endforeach
                </select>
                <p class="text-xs text-gray-400 mt-1">Sets the subscription end date from today.</p>
            </div>
            <div>
                <label class="block text-sm text-gray-600 mb-1">WhatsApp device limit</label>
                <input name="max_devices" type="number" min="0" value="{{ old('max_devices', $ws->max_devices ?? 1) }}" required
                       class="block w-full rounded-lg border-gray-300 text-sm focus:ring-brand focus:border-brand">
                <p class="text-xs text-gray-400 mt-1">Number of WhatsApp accounts this client can connect. 0 = unlimited.</p>
            </div>
        </div>
    </div>

    <div class="bg-white rounded-xl border border-gray-200 shadow-sm p-5" x-data="{
            all: {{ $selectedModules->count() === count($modules) ? 'true' : 'false' }},
            toggleAll() { document.querySelectorAll('.mod-check').forEach(c => c.checked = this.all); }
         }">
        <div class="flex items-center gap-3 mb-4">
            <h3 class="font-semibold text-gray-800">Enabled modules <span class="text-xs text-gray-400">{{ count($modules) }} modules</span></h3>
            <label class="ml-auto inline-flex items-center gap-2 text-sm text-gray-600">
                <input type="checkbox" x-model="all" @change="toggleAll()" class="rounded border-gray-300 text-brand focus:ring-brand">
                Select all
            </label>
        </div>
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-2">
            @foreach ($modules as $key => $cfg)
                <label class="flex items-start gap-3 px-3 py-2.5 rounded-lg border border-gray-200 cursor-pointer hover:bg-gray-50 has-[:checked]:border-brand has-[:checked]:bg-brand/5">
                    <input type="checkbox" name="modules[]" value="{{ $key }}" @checked($selectedModules->contains($key))
                           class="mod-check mt-0.5 rounded border-gray-300 text-brand focus:ring-brand">
                    <span class="min-w-0">
                        <span class="block text-sm font-medium text-gray-800">{{ $cfg['label'] }}</span>
                        <span class="block text-xs text-gray-500">{{ $cfg['desc'] ?? '' }}</span>
                    </span>
                </label>
            @endforeach
        </div>
        <p class="text-xs text-gray-400 mt-3">Unticked modules are hidden from this client's menu and blocked if they try the URL directly.</p>
    </div>
</div>
