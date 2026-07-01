<x-app-layout>
    <x-slot name="header">Single message</x-slot>

    <div class="max-w-2xl">
        <x-card title="Send one WhatsApp message" subtitle="Pick a number to send from, who to send to, and your message.">
            <form method="POST" action="{{ route('single-message.send') }}" class="space-y-5"
                  x-data="{ source: '{{ old('template_id') ? 'template' : 'compose' }}' }">
                @csrf

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <x-input-label for="whatsapp_instance_id" value="Send from" />
                        <select id="whatsapp_instance_id" name="whatsapp_instance_id" required
                                class="mt-1 block w-full rounded-lg border-gray-300 text-sm focus:ring-brand focus:border-brand">
                            <option value="">— Choose a number —</option>
                            @foreach ($devices as $device)
                                <option value="{{ $device->id }}" @selected(old('whatsapp_instance_id') == $device->id) @disabled(! $device->isConnected())>
                                    {{ $device->name }} {{ $device->isConnected() ? '' : '(not connected)' }}
                                </option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <x-input-label for="phone" value="Send to (phone)" />
                        <x-text-input id="phone" name="phone" class="block mt-1 w-full" placeholder="971501234567" :value="old('phone')" required />
                        <p class="text-xs text-gray-500 mt-1">Digits with country code, no + or spaces.</p>
                    </div>
                </div>

                <div class="flex gap-2">
                    <label class="flex-1">
                        <input type="radio" x-model="source" value="compose" class="sr-only peer">
                        <div class="px-4 py-2 rounded-lg border text-sm text-center cursor-pointer peer-checked:border-brand peer-checked:bg-brand/5">Write a message</div>
                    </label>
                    <label class="flex-1">
                        <input type="radio" x-model="source" value="template" class="sr-only peer">
                        <div class="px-4 py-2 rounded-lg border text-sm text-center cursor-pointer peer-checked:border-brand peer-checked:bg-brand/5">Use a template</div>
                    </label>
                </div>

                <div x-show="source === 'compose'">
                    <x-input-label for="body" value="Message" />
                    <textarea id="body" name="body" rows="5" :disabled="source !== 'compose'"
                              class="mt-1 block w-full rounded-lg border-gray-300 text-sm focus:ring-brand focus:border-brand"
                              placeholder="Hi there, ...">{{ old('body') }}</textarea>
                </div>

                <div x-show="source === 'template'" x-cloak>
                    <x-input-label for="template_id" value="Template" />
                    <select id="template_id" name="template_id" :disabled="source !== 'template'"
                            class="mt-1 block w-full rounded-lg border-gray-300 text-sm focus:ring-brand focus:border-brand">
                        <option value="">— Choose a template —</option>
                        @foreach ($templates as $template)
                            <option value="{{ $template->id }}" @selected(old('template_id') == $template->id)>{{ $template->name }} ({{ $template->type }})</option>
                        @endforeach
                    </select>
                    @if ($templates->isEmpty())
                        <p class="text-xs text-gray-500 mt-1">No templates yet. <a href="{{ route('templates.create') }}" class="text-brand">Create one</a> — text, image, poll or buttons.</p>
                    @endif
                </div>

                <div class="flex items-center gap-3">
                    <x-btn type="submit" variant="primary">Send message</x-btn>
                    <x-btn :href="route('dashboard')" variant="ghost">Cancel</x-btn>
                </div>
            </form>
        </x-card>
    </div>
</x-app-layout>
