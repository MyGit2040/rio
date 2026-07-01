<x-app-layout>
    <x-slot name="header">New campaign</x-slot>

    @php $connectedCount = $devices->where('status', 'open')->count(); @endphp

    <div class="max-w-2xl"
         x-data="{
            source: '{{ old('template_id') ? 'template' : 'compose' }}',
            audience: '{{ old('audience', 'all') }}',
            schedule: '{{ old('schedule', 'now') }}'
         }">

        @if ($devices->isEmpty())
            <div class="mb-6 rounded-xl bg-yellow-50 border border-yellow-200 text-yellow-800 px-5 py-4 text-sm">
                You have no devices. <a href="{{ route('devices.index') }}" class="font-medium underline">Add a WhatsApp device</a> first.
            </div>
        @elseif ($connectedCount === 0)
            <div class="mb-6 rounded-xl bg-yellow-50 border border-yellow-200 text-yellow-800 px-5 py-4 text-sm">
                No device is connected yet. You can build the campaign, but messages only send once a device is linked.
            </div>
        @endif

        <form method="POST" action="{{ route('campaigns.store') }}" class="space-y-6">
            @csrf

            <x-card title="Basics">
                <div class="space-y-4">
                    <div>
                        <x-input-label for="name" value="Campaign name" />
                        <x-text-input id="name" name="name" class="block mt-1 w-full" :value="old('name')" required />
                    </div>
                    <div>
                        <x-input-label value="Send from device(s)" />
                        <p class="text-xs text-gray-500 mb-2">Pick one or more numbers. Each contact is stuck to one — the same customer always hears from the same account.</p>
                        <div class="space-y-2">
                            @foreach ($devices as $device)
                                <label class="flex items-center gap-2 px-3 py-2 rounded-lg border border-gray-200 cursor-pointer hover:bg-gray-50">
                                    <input type="checkbox" name="device_ids[]" value="{{ $device->id }}"
                                           @checked(collect(old('device_ids'))->contains($device->id))
                                           class="rounded border-gray-300 text-brand focus:ring-brand">
                                    <span class="text-sm text-gray-800">{{ $device->name }}</span>
                                    <span class="text-xs ml-auto {{ $device->status === 'open' ? 'text-green-600' : 'text-gray-400' }}">
                                        {{ $device->status === 'open' ? 'connected' : 'not connected' }}
                                    </span>
                                </label>
                            @endforeach
                        </div>
                    </div>
                </div>
            </x-card>

            <x-card title="Message">
                <div class="space-y-4">
                    <div class="flex gap-2">
                        <label class="flex-1">
                            <input type="radio" x-model="source" value="compose" class="sr-only peer">
                            <div class="px-4 py-2 rounded-lg border text-sm text-center cursor-pointer peer-checked:border-green-500 peer-checked:bg-green-50">Write a message</div>
                        </label>
                        <label class="flex-1">
                            <input type="radio" x-model="source" value="template" class="sr-only peer">
                            <div class="px-4 py-2 rounded-lg border text-sm text-center cursor-pointer peer-checked:border-green-500 peer-checked:bg-green-50">Use a template</div>
                        </label>
                    </div>

                    <div x-show="source === 'compose'">
                        <x-input-label for="body" value="Message" />
                        <div class="mt-1">@include('templates._message-toolbar', ['target' => 'body'])</div>
                        <textarea id="body" name="body" rows="5" :disabled="source !== 'compose'"
                                  class="block w-full rounded-lg border-gray-300 text-sm focus:ring-green-500 focus:border-green-500"
                                  placeholder="Hi @{{name}}, ...">{{ old('body') }}</textarea>
                        <p class="text-xs text-gray-500 mt-1">Use <code>@{{name}}</code> and <code>@{{phone}}</code> to personalise.</p>
                    </div>

                    <div x-show="source === 'template'" x-cloak>
                        <x-input-label for="template_id" value="Template" />
                        <select id="template_id" name="template_id" :disabled="source !== 'template'"
                                class="mt-1 block w-full rounded-lg border-gray-300 text-sm focus:ring-green-500 focus:border-green-500">
                            <option value="">— Choose a template —</option>
                            @foreach ($templates as $template)
                                <option value="{{ $template->id }}" @selected(old('template_id') == $template->id)>{{ $template->name }} ({{ $template->type }})</option>
                            @endforeach
                        </select>
                        @if ($templates->isEmpty())
                            <p class="text-xs text-gray-500 mt-1">No templates yet. <a href="{{ route('templates.create') }}" class="text-green-600">Create one</a> (polls live here).</p>
                        @endif
                    </div>
                </div>
            </x-card>

            <x-card title="Audience">
                <div class="space-y-4">
                    <div class="grid grid-cols-3 gap-2">
                        <label>
                            <input type="radio" name="audience" x-model="audience" value="all" class="sr-only peer">
                            <div class="px-4 py-2 rounded-lg border text-sm text-center cursor-pointer peer-checked:border-green-500 peer-checked:bg-green-50">All contacts</div>
                        </label>
                        <label>
                            <input type="radio" name="audience" x-model="audience" value="groups" class="sr-only peer">
                            <div class="px-4 py-2 rounded-lg border text-sm text-center cursor-pointer peer-checked:border-green-500 peer-checked:bg-green-50">Groups</div>
                        </label>
                        <label>
                            <input type="radio" name="audience" x-model="audience" value="tag" class="sr-only peer">
                            <div class="px-4 py-2 rounded-lg border text-sm text-center cursor-pointer peer-checked:border-green-500 peer-checked:bg-green-50">By tag</div>
                        </label>
                    </div>

                    <div x-show="audience === 'groups'" x-cloak>
                        <div class="flex flex-wrap gap-2">
                            @forelse ($groups as $group)
                                <label class="inline-flex items-center gap-2 px-3 py-1.5 rounded-lg border border-gray-200 cursor-pointer hover:bg-gray-50">
                                    <input type="checkbox" name="group_ids[]" value="{{ $group->id }}"
                                           @checked(collect(old('group_ids'))->contains($group->id))
                                           class="rounded border-gray-300 text-green-600 focus:ring-green-500">
                                    <span class="text-sm">{{ $group->name }} ({{ $group->contacts_count }})</span>
                                </label>
                            @empty
                                <p class="text-sm text-gray-500">No groups yet.</p>
                            @endforelse
                        </div>
                    </div>

                    <div x-show="audience === 'tag'" x-cloak>
                        <x-input-label for="tag" value="Tag" />
                        <x-text-input id="tag" name="tag" class="block mt-1 w-full" placeholder="vip" :value="old('tag')" />
                        <p class="text-xs text-gray-500 mt-1">Sends to every opted-in contact carrying this tag.</p>
                    </div>
                </div>
            </x-card>

            <x-card title="Sending speed &amp; schedule">
                <div class="space-y-4">
                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <x-input-label for="min_delay" value="Min delay (seconds)" />
                            <x-text-input id="min_delay" name="min_delay" type="number" min="1" max="600" class="block mt-1 w-full" :value="old('min_delay', $delayMin)" required />
                        </div>
                        <div>
                            <x-input-label for="max_delay" value="Max delay (seconds)" />
                            <x-text-input id="max_delay" name="max_delay" type="number" min="1" max="600" class="block mt-1 w-full" :value="old('max_delay', $delayMax)" required />
                        </div>
                    </div>
                    <p class="text-xs text-gray-500">A pause between each message keeps a steady send rate.</p>

                    <div>
                        <x-input-label for="max_retries" value="Retries on failure" />
                        <x-text-input id="max_retries" name="max_retries" type="number" min="0" max="10" class="block mt-1 w-32" :value="old('max_retries', 3)" />
                        <p class="text-xs text-gray-500 mt-1">How many times to retry a message that fails to send.</p>
                    </div>

                    <label class="flex items-start gap-2">
                        <input type="hidden" name="track_links" value="0">
                        <input type="checkbox" name="track_links" value="1" @checked(old('track_links'))
                               class="mt-0.5 rounded border-gray-300 text-brand focus:ring-brand">
                        <span class="text-sm text-gray-700">Track link clicks<span class="block text-xs text-gray-500">Links in your message are shortened so clicks show up in Reports.</span></span>
                    </label>

                    <div class="flex gap-2">
                        <label class="flex-1">
                            <input type="radio" name="schedule" x-model="schedule" value="now" class="sr-only peer">
                            <div class="px-4 py-2 rounded-lg border text-sm text-center cursor-pointer peer-checked:border-green-500 peer-checked:bg-green-50">Send now</div>
                        </label>
                        <label class="flex-1">
                            <input type="radio" name="schedule" x-model="schedule" value="later" class="sr-only peer">
                            <div class="px-4 py-2 rounded-lg border text-sm text-center cursor-pointer peer-checked:border-green-500 peer-checked:bg-green-50">Schedule</div>
                        </label>
                    </div>
                    <div x-show="schedule === 'later'" x-cloak>
                        <x-input-label for="scheduled_at" value="Send at" />
                        <input id="scheduled_at" name="scheduled_at" type="datetime-local" :disabled="schedule !== 'later'"
                               value="{{ old('scheduled_at') }}"
                               class="mt-1 block w-full rounded-lg border-gray-300 text-sm focus:ring-green-500 focus:border-green-500">
                    </div>
                </div>
            </x-card>

            <div class="flex items-center gap-3">
                <x-btn type="submit" variant="primary">Create campaign</x-btn>
                <x-btn :href="route('campaigns.index')" variant="ghost">Cancel</x-btn>
            </div>
        </form>
    </div>
</x-app-layout>
