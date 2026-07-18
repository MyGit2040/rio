<x-app-layout>
    <x-slot name="header">{{ $contact->name ?? '+'.$contact->phone }}</x-slot>

    <div class="mb-4 flex items-center gap-3 flex-wrap">
        <a href="{{ route('contacts.index') }}" class="text-sm text-gray-500 hover:text-gray-700">&larr; Contacts</a>
        <div class="ml-auto flex items-center gap-2">
            <x-btn :href="route('inbox.show', $contact)" variant="secondary">Open chat</x-btn>
            <x-btn :href="route('contacts.edit', $contact)" variant="primary">Edit</x-btn>
        </div>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <div class="space-y-6">
            <x-card title="Profile">
                <dl class="text-sm space-y-2">
                    <div class="flex justify-between gap-3"><dt class="text-gray-500">Phone</dt><dd class="text-gray-800">+{{ $contact->phone }}</dd></div>
                    <div class="flex justify-between gap-3"><dt class="text-gray-500">Email</dt><dd class="text-gray-800 truncate">{{ $contact->email ?: '—' }}</dd></div>
                    <div class="flex justify-between gap-3"><dt class="text-gray-500">Country</dt><dd class="text-gray-800">{{ $contact->country ?: '—' }}</dd></div>
                    <div class="flex justify-between gap-3"><dt class="text-gray-500">WhatsApp</dt><dd>
                        <x-badge :color="$contact->wa_status === 'valid' ? 'green' : ($contact->wa_status === 'invalid' ? 'red' : 'gray')">{{ ucfirst($contact->wa_status ?? 'unverified') }}</x-badge>
                    </dd></div>
                    <div class="flex justify-between gap-3"><dt class="text-gray-500">Consent</dt><dd>
                        <x-badge :color="$contact->opted_out ? 'red' : 'green'">{{ $contact->opted_out ? 'Opted out' : 'Active' }}</x-badge>
                    </dd></div>
                </dl>

                @if (!empty($contact->tags))
                    <div class="mt-4 pt-4 border-t border-gray-100">
                        <p class="text-xs text-gray-500 mb-2">Tags</p>
                        <div class="flex flex-wrap gap-1">@foreach ($contact->tags as $tag)<x-badge color="purple">{{ $tag }}</x-badge>@endforeach</div>
                    </div>
                @endif

                @if (!empty($contact->attributes))
                    <div class="mt-4 pt-4 border-t border-gray-100">
                        <p class="text-xs text-gray-500 mb-2">Custom fields</p>
                        <dl class="text-sm space-y-1">
                            @foreach ($contact->attributes as $k => $v)
                                @if (! is_array($v))
                                    <div class="flex justify-between gap-3"><dt class="text-gray-500">{{ $k }}</dt><dd class="text-gray-800 truncate">{{ $v }}</dd></div>
                                @endif
                            @endforeach
                        </dl>
                    </div>
                @endif

                @if ($contact->groups->count())
                    <div class="mt-4 pt-4 border-t border-gray-100">
                        <p class="text-xs text-gray-500 mb-2">Groups</p>
                        <div class="flex flex-wrap gap-1">@foreach ($contact->groups as $g)<x-badge>{{ $g->name }}</x-badge>@endforeach</div>
                    </div>
                @endif
            </x-card>
        </div>

        <div class="lg:col-span-2">
            <x-card title="Activity timeline" flush>
                <ul class="divide-y divide-gray-100">
                    @forelse ($timeline as $msg)
                        <li class="px-5 py-3 flex items-start gap-3">
                            <span class="mt-1 w-2 h-2 rounded-full shrink-0 {{ $msg->direction === 'in' ? 'bg-green-500' : 'bg-blue-400' }}"></span>
                            <div class="min-w-0 flex-1">
                                <p class="text-sm text-gray-700 whitespace-pre-line break-words">{{ $msg->body ?: '['.$msg->type.']' }}</p>
                                <p class="text-xs text-gray-400 mt-0.5">
                                    {{ $msg->direction === 'in' ? 'Received' : 'Sent' }}
                                    @if ($msg->instance) · {{ $msg->instance->name }} @endif
                                    · {{ $msg->created_at?->format('M j, Y g:i A') }}
                                </p>
                            </div>
                        </li>
                    @empty
                        <li class="px-5 py-10 text-center text-gray-500">No messages with this contact yet.</li>
                    @endforelse
                </ul>
            </x-card>
        </div>
    </div>
</x-app-layout>
