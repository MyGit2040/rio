<x-app-layout>
    <x-slot name="header">Edit campaign</x-slot>

    <div class="max-w-2xl">
        <div class="mb-4 flex items-center gap-3 flex-wrap">
            <a href="{{ route('campaigns.show', $campaign) }}" class="text-sm text-gray-500 hover:text-gray-700">&larr; {{ $campaign->name }}</a>
            <x-campaign-status :status="$campaign->status" />
        </div>

        @if ($campaign->sent > 0)
            <div class="mb-6 rounded-xl bg-blue-50 border border-blue-200 text-blue-800 px-5 py-4 text-sm">
                {{ number_format($campaign->sent) }} message(s) already went out and are not affected.
                Your changes apply to the <strong>{{ number_format(max(0, $campaign->total - $campaign->sent - $campaign->failed)) }} remaining</strong> recipient(s)
                {{ $campaign->status === 'paused' ? 'when you press Resume' : 'when sending starts' }}.
            </div>
        @endif

        <form method="POST" action="{{ route('campaigns.update', $campaign) }}" class="space-y-6">
            @csrf
            @method('PUT')

            <x-card title="Campaign setup">
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 text-sm">
                    <div><p class="text-xs text-gray-500">Message format</p><p class="font-medium text-gray-800">{{ ucfirst($campaign->type) }}</p></div>
                    <div><p class="text-xs text-gray-500">Message source</p><p class="font-medium text-gray-800">{{ $campaign->template?->name ?? 'Custom message snapshot' }}</p></div>
                    <div><p class="text-xs text-gray-500">Link tracking</p><p class="font-medium text-gray-800">{{ $campaign->track_links ? 'Enabled' : 'Disabled' }}</p></div>
                    <div><p class="text-xs text-gray-500">Audience</p><p class="font-medium text-gray-800">{{ number_format($campaign->total) }} saved recipient{{ $campaign->total === 1 ? '' : 's' }}</p></div>
                </div>
                <p class="mt-3 text-xs text-gray-500">The message source, link-tracking choice and audience are shown here for clarity. The recipient list is fixed once created so changing a draft cannot accidentally add or remove people.</p>
            </x-card>

            <x-card title="Basics">
                <div class="space-y-4">
                    <div>
                        <x-input-label for="name" value="Campaign name" />
                        <x-text-input id="name" name="name" class="block mt-1 w-full" :value="old('name', $campaign->name)" required />
                    </div>
                    <div>
                        <x-input-label value="Send from device(s)" />
                        <p class="text-xs text-gray-500 mb-2">Pick one or more numbers to send from. Set a <strong>max</strong> to cap how many messages that number may send (blank = no limit).</p>
                        @include('campaigns._device-picker', [
                            'selected' => $campaign->device_ids ?: array_filter([$campaign->whatsapp_instance_id]),
                            'limits'   => $campaign->device_limits ?? [],
                        ])
                        @error('device_ids')<p class="text-xs text-red-600 mt-1">{{ $message }}</p>@enderror
                    </div>

                    <div>
                        <x-input-label for="rotate_every" value="Rotate to the next number after every … messages" />
                        <x-text-input id="rotate_every" name="rotate_every" type="number" min="0" class="block mt-1 w-40" :value="old('rotate_every', $campaign->rotate_every ?? 0)" />
                        <p class="text-xs text-gray-500 mt-1">
                            e.g. <strong>50</strong> = send 50 messages from the first number, then switch to the next, and so on (only matters when you pick more than one number).<br>
                            <strong>0</strong> = spread evenly by contact — the same customer always hears from the same number.
                        </p>
                    </div>
                </div>
            </x-card>

            <x-card title="Message">
                <div class="space-y-4">
                    @if ($campaign->type === 'poll')
                        <div>
                            <x-input-label for="poll_question" value="Poll question" />
                            <x-text-input id="poll_question" name="poll_question" class="block mt-1 w-full" :value="old('poll_question', data_get($campaign->poll, 'question'))" required />
                        </div>

                        <div x-data="{ options: {{ Js::from(old('poll_options', data_get($campaign->poll, 'options', []))) }} }">
                            <x-input-label value="Poll options" />
                            <template x-for="(opt, i) in options" :key="i">
                                <div class="flex items-center gap-2 mt-2">
                                    <input type="text" :name="`poll_options[${i}]`" x-model="options[i]" maxlength="100"
                                           class="block w-full rounded-lg border-gray-300 text-sm focus:ring-green-500 focus:border-green-500">
                                    <button type="button" @click="options.splice(i, 1)" x-show="options.length > 2"
                                            class="text-gray-400 hover:text-red-500 text-lg leading-none px-1" title="Remove option">&times;</button>
                                </div>
                            </template>
                            <button type="button" @click="options.push('')"
                                    class="mt-2 text-sm text-brand hover:underline">+ Add option</button>
                            @error('poll_options')<p class="text-xs text-red-600 mt-1">{{ $message }}</p>@enderror
                        </div>

                        <label class="flex items-center gap-2">
                            <input type="hidden" name="poll_multiple" value="0">
                            <input type="checkbox" name="poll_multiple" value="1" @checked(old('poll_multiple', data_get($campaign->poll, 'multiple')))
                                   class="rounded border-gray-300 text-brand focus:ring-brand">
                            <span class="text-sm text-gray-700">Allow choosing more than one answer</span>
                        </label>
                    @endif

                    @if (in_array($campaign->type, ['media', 'poll'], true))
                        <div>
                            <x-input-label for="media_url" :value="$campaign->type === 'poll' ? 'Image / video URL sent above the poll (optional)' : 'Media URL'" />
                            <x-text-input id="media_url" name="media_url" class="block mt-1 w-full" :value="old('media_url', $campaign->media_url)" placeholder="https://…" />
                        </div>
                    @endif

                    <div>
                        <x-input-label for="body" :value="$campaign->type === 'poll' ? 'Message sent with the poll (optional)' : ($campaign->type === 'media' ? 'Caption' : 'Message')" />
                        <div class="mt-1">@include('templates._message-toolbar', ['target' => 'body'])</div>
                        <textarea id="body" name="body" rows="5"
                                  class="block w-full rounded-lg border-gray-300 text-sm focus:ring-green-500 focus:border-green-500"
                                  placeholder="Hi @{{name}}, ...">{{ old('body', $campaign->body) }}</textarea>
                        @include('templates._spam-live', ['target' => 'body'])
                        <p class="text-xs text-gray-500 mt-1">Use <code>@{{name}}</code> and <code>@{{phone}}</code> to personalise.</p>
                        @error('body')<p class="text-xs text-red-600 mt-1">{{ $message }}</p>@enderror
                    </div>

                    <div>
                        <x-input-label for="footer" value="Footer / signature (optional)" />
                        <textarea id="footer" name="footer" rows="2"
                                  class="mt-1 block w-full rounded-lg border-gray-300 text-sm focus:ring-green-500 focus:border-green-500"
                                  placeholder="Powered by Your Company">{{ old('footer', $campaign->footer) }}</textarea>
                        <p class="text-xs text-gray-500 mt-1">Auto-added to the end of every message and variant.</p>
                    </div>

                    <div x-data="{ variants: {{ Js::from(old('variants', $campaign->variants ?? [])) }} }">
                        <x-input-label value="Message variants (A/B copy, optional)" />
                        <p class="text-xs text-gray-500 mt-1">Each contact gets the next variant in rotation (main message first). Remove all to always send the main message.</p>
                        <template x-for="(v, i) in variants" :key="i">
                            <div class="flex items-start gap-2 mt-2">
                                <textarea :name="`variants[${i}]`" x-model="variants[i]" rows="2" maxlength="4096"
                                          class="block w-full rounded-lg border-gray-300 text-sm focus:ring-green-500 focus:border-green-500"></textarea>
                                <button type="button" @click="variants.splice(i, 1)"
                                        class="text-gray-400 hover:text-red-500 text-lg leading-none px-1 mt-2" title="Remove variant">&times;</button>
                            </div>
                        </template>
                        <button type="button" @click="variants.push('')"
                                class="mt-2 text-sm text-brand hover:underline">+ Add variant</button>
                    </div>

                    @if (in_array($campaign->type, ['buttons', 'carousel'], true))
                        <p class="text-xs text-gray-500 rounded-lg bg-gray-50 border border-gray-200 px-3 py-2">
                            The {{ $campaign->type === 'buttons' ? 'button set' : 'carousel cards' }} were snapshotted when the campaign was created and can't be changed here — the text above can.
                        </p>
                    @endif
                </div>
            </x-card>

            <x-card :title="'Sending speed'.($campaign->status === 'scheduled' ? ' & schedule' : '')">
                <div class="space-y-4">
                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <x-input-label for="min_delay" value="Min delay (seconds)" />
                            <x-text-input id="min_delay" name="min_delay" type="number" min="1" max="600" class="block mt-1 w-full" :value="old('min_delay', $campaign->min_delay)" required />
                        </div>
                        <div>
                            <x-input-label for="max_delay" value="Max delay (seconds)" />
                            <x-text-input id="max_delay" name="max_delay" type="number" min="1" max="600" class="block mt-1 w-full" :value="old('max_delay', $campaign->max_delay)" required />
                        </div>
                    </div>
                    <p class="text-xs text-gray-500">A random pause between each message keeps a steady send rate. For polls with text or an image, the same range is also used between the prelude and the poll.</p>
                    @error('max_delay')<p class="text-xs text-red-600">{{ $message }}</p>@enderror

                    <div>
                        <x-input-label for="max_retries" value="Retries on failure" />
                        <x-text-input id="max_retries" name="max_retries" type="number" min="0" max="10" class="block mt-1 w-32" :value="old('max_retries', $campaign->max_retries)" />
                        <p class="text-xs text-gray-500 mt-1">How many times to retry a message that fails to send.</p>
                    </div>

                    @if ($campaign->status === 'scheduled')
                        <div>
                            <x-input-label for="scheduled_at" value="Send at" />
                            <input id="scheduled_at" name="scheduled_at" type="datetime-local"
                                   value="{{ old('scheduled_at', $campaign->scheduled_at?->format('Y-m-d\TH:i')) }}"
                                   class="mt-1 block w-full rounded-lg border-gray-300 text-sm focus:ring-green-500 focus:border-green-500">
                            @error('scheduled_at')<p class="text-xs text-red-600 mt-1">{{ $message }}</p>@enderror
                        </div>
                    @endif
                </div>
            </x-card>

            <div class="flex items-center gap-3">
                <x-btn type="submit" variant="primary">Save changes</x-btn>
                <x-btn :href="route('campaigns.show', $campaign)" variant="ghost">Cancel</x-btn>
            </div>
        </form>
    </div>
</x-app-layout>
