{{-- Device checkboxes + per-number "max" caps. Pass: $devices (all instances),
     $selected (ids to pre-check), $limits (id => cap). old() wins on re-render. --}}
@php
    $selected = collect(old('device_ids', $selected ?? []))->map(fn ($v) => (int) $v)->all();
    $limits = old('device_limits', $limits ?? []);
@endphp
<div class="space-y-2">
    @foreach ($devices as $device)
        <div class="flex items-center gap-2 px-3 py-2 rounded-lg border border-gray-200 hover:bg-gray-50">
            <label class="flex items-center gap-2 cursor-pointer flex-1 min-w-0">
                <input type="checkbox" name="device_ids[]" value="{{ $device->id }}"
                       @checked(in_array((int) $device->id, $selected, true))
                       class="rounded border-gray-300 text-brand focus:ring-brand">
                <span class="text-sm text-gray-800 truncate">{{ $device->name }}</span>
                <span class="text-xs {{ $device->status === 'open' ? 'text-green-600' : 'text-gray-400' }}">
                    {{ $device->status === 'open' ? 'connected' : 'not connected' }}
                </span>
            </label>
            <div class="flex items-center gap-1 shrink-0">
                <input type="number" min="0" step="1" inputmode="numeric"
                       name="device_limits[{{ $device->id }}]"
                       value="{{ $limits[$device->id] ?? '' }}"
                       placeholder="All"
                       title="Max messages from this number (blank = no limit)"
                       class="w-20 rounded-lg border-gray-300 text-sm focus:ring-brand focus:border-brand">
                <span class="text-xs text-gray-400">max</span>
            </div>
        </div>
    @endforeach
</div>
