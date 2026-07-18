@php
    $contact = $contact ?? null;
    $selectedGroups = collect(old('groups', $contact ? $contact->groups->pluck('id')->all() : []));

    if (old('attr_keys')) {
        $attrRows = collect(old('attr_keys'))->map(fn ($k, $i) => ['key' => $k, 'value' => old('attr_values')[$i] ?? ''])->values();
    } else {
        $attrRows = collect($contact->attributes ?? [])->map(fn ($v, $k) => ['key' => $k, 'value' => is_array($v) ? '' : $v])->values();
    }
    if ($attrRows->isEmpty()) {
        $attrRows = collect([['key' => '', 'value' => '']]);
    }
@endphp

<div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
    <div>
        <x-input-label for="name" value="Name" />
        <x-text-input id="name" name="name" class="block mt-1 w-full" :value="old('name', $contact->name ?? '')" />
    </div>
    <div>
        <x-input-label for="phone" value="Phone (with country code)" />
        <x-text-input id="phone" name="phone" class="block mt-1 w-full" placeholder="971501234567" :value="old('phone', $contact->phone ?? '')" required />
        <p class="text-xs text-gray-500 mt-1">Digits only, include the country code. No + or spaces.</p>
    </div>
    <div>
        <x-input-label for="email" value="Email" />
        <x-text-input id="email" name="email" type="email" class="block mt-1 w-full" :value="old('email', $contact->email ?? '')" />
    </div>
    <div>
        <x-input-label for="country" value="Country" />
        <x-text-input id="country" name="country" class="block mt-1 w-full" :value="old('country', $contact->country ?? '')" />
    </div>
</div>

<div class="mt-4">
    <x-input-label value="Groups" />
    <div class="mt-2 flex flex-wrap gap-2">
        @forelse ($groups as $group)
            <label class="inline-flex items-center gap-2 px-3 py-1.5 rounded-lg border border-gray-200 cursor-pointer hover:bg-gray-50">
                <input type="checkbox" name="groups[]" value="{{ $group->id }}" @checked($selectedGroups->contains($group->id))
                       class="rounded border-gray-300 text-green-600 focus:ring-green-500">
                <span class="text-sm">{{ $group->name }}</span>
            </label>
        @empty
            <p class="text-sm text-gray-500">No groups yet. <a href="{{ route('groups.create') }}" class="text-green-600">Create one</a>.</p>
        @endforelse
    </div>
</div>

<div class="mt-4">
    <x-input-label for="tags" value="Tags" />
    <x-text-input id="tags" name="tags" class="block mt-1 w-full" placeholder="vip, lead, dubai"
                  :value="old('tags', isset($contact) ? collect($contact->tags ?? [])->join(', ') : '')" />
    <p class="text-xs text-gray-500 mt-1">Comma-separated labels for segmenting — campaigns and reports can target a tag.</p>
</div>

<div class="mt-4" x-data="{ rows: @js($attrRows->values()) }">
    <x-input-label value="Custom fields (merge tags)" />
    <p class="text-xs text-gray-500 mb-2">Personalise messages with these — a field named <code>company</code> is written <code>@{{company}}</code> in your template.</p>
    <div class="space-y-2">
        <template x-for="(row, i) in rows" :key="i">
            <div class="flex items-center gap-2">
                <input type="text" name="attr_keys[]" x-model="row.key" placeholder="field name"
                       class="w-1/3 rounded-lg border-gray-300 text-sm focus:ring-green-500 focus:border-green-500">
                <input type="text" name="attr_values[]" x-model="row.value" placeholder="value"
                       class="flex-1 rounded-lg border-gray-300 text-sm focus:ring-green-500 focus:border-green-500">
                <button type="button" @click="rows.splice(i, 1)" class="text-red-500 px-2">&times;</button>
            </div>
        </template>
    </div>
    <button type="button" @click="rows.push({ key: '', value: '' })" class="mt-2 text-sm text-green-600 font-medium">+ Add field</button>
</div>

<label class="mt-4 inline-flex items-center gap-2">
    <input type="hidden" name="opted_out" value="0">
    <input type="checkbox" name="opted_out" value="1" @checked(old('opted_out', $contact->opted_out ?? false))
           class="rounded border-gray-300 text-green-600 focus:ring-green-500">
    <span class="text-sm text-gray-700">Opted out (blocks all marketing campaigns)</span>
</label>
