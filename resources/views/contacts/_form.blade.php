@php($selectedGroups = collect(old('groups', isset($contact) ? $contact->groups->pluck('id')->all() : [])))

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

<label class="mt-4 inline-flex items-center gap-2">
    <input type="hidden" name="opted_out" value="0">
    <input type="checkbox" name="opted_out" value="1" @checked(old('opted_out', $contact->opted_out ?? false))
           class="rounded border-gray-300 text-green-600 focus:ring-green-500">
    <span class="text-sm text-gray-700">Opted out (won't receive campaigns)</span>
</label>
