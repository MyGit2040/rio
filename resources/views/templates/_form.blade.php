@php
    $pollOptions = old('poll_options', data_get($template, 'poll.options', ['', '']));
    if (count($pollOptions) < 2) {
        $pollOptions = array_pad($pollOptions, 2, '');
    }
@endphp

<div x-data="{
        type: '{{ old('type', $template->type ?? 'text') }}',
        options: @js(array_values($pollOptions)),
        variants: @js(array_values(old('variants', data_get($template, 'variants') ?? []))),
        pollMediaType: @js(old('poll_media_type', $template->type === 'poll' ? ($template->media_type ?? 'image') : 'image')),
        pollMediaUrl: @js(old('poll_media_url', $template->type === 'poll' ? ($template->media_url ?? '') : '')),
        buttonsTitle: @js(old('buttons_title', data_get($template, 'buttons.title', ''))),
        buttonsFooter: @js(old('buttons_footer', data_get($template, 'buttons.footer', ''))),
        buttons: @js(old('buttons', data_get($template, 'buttons.items') ?: [['type' => 'reply', 'text' => '', 'value' => '']])),
        cards: @js(old('cards', data_get($template, 'cards') ?: [['image' => '', 'title' => '', 'body' => '', 'buttons' => []]])),
        addCard() { if (this.cards.length < 10) this.cards.push({ image: '', title: '', body: '', buttons: [] }); },
        addCardButton(ci) { if (this.cards[ci].buttons.length < 2) this.cards[ci].buttons.push({ type: 'url', text: '', value: '' }); },
        addOption() { this.options.push(''); },
        removeOption(i) { if (this.options.length > 2) this.options.splice(i, 1); },
        uploading: false,
        async upload(e, setter) {
            const file = e.target.files[0];
            if (! file) return;
            this.uploading = true;
            const fd = new FormData();
            fd.append('file', file);
            try {
                const res = await fetch('{{ route('uploads.store') }}', {
                    method: 'POST',
                    headers: { 'X-CSRF-TOKEN': '{{ csrf_token() }}', 'Accept': 'application/json' },
                    body: fd,
                });
                const data = await res.json();
                if (data.url) setter(data.url);
                else alert(data.message || 'Upload failed.');
            } catch (err) { alert('Upload failed.'); } finally { this.uploading = false; e.target.value = ''; }
        }
     }"
     class="space-y-5">

    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
        <div>
            <x-input-label for="name" value="Template name" />
            <x-text-input id="name" name="name" class="block mt-1 w-full" :value="old('name', $template->name ?? '')" required />
        </div>
        <div>
            <x-input-label for="type" value="Type" />
            <select id="type" name="type" x-model="type" class="mt-1 block w-full rounded-lg border-gray-300 text-sm focus:ring-green-500 focus:border-green-500">
                <option value="text">Text message</option>
                <option value="media">Image / Video / File</option>
                <option value="poll">Poll</option>
                <option value="buttons">Buttons</option>
                <option value="carousel">Carousel</option>
            </select>
        </div>
    </div>

    {{-- Text / caption body --}}
    <div>
        <x-input-label for="body">
            <span x-text="type === 'media' ? 'Caption (optional)' : 'Message'"></span>
        </x-input-label>
        <div class="mt-1">@include('templates._message-toolbar', ['target' => 'body'])</div>
        <textarea id="body" name="body" rows="5"
                  class="block w-full rounded-lg border-gray-300 text-sm focus:ring-green-500 focus:border-green-500"
                  placeholder="Hi @{{name}}, ...">{{ old('body', $template->body ?? '') }}</textarea>
        @include('templates._spam-live', ['target' => 'body'])
        <p class="text-xs text-gray-500 mt-1">Use <code>@{{name}}</code> and <code>@{{phone}}</code> to personalise each message.</p>
    </div>

    {{-- Message variants (A/B copy rotation) --}}
    <div x-show="type !== 'poll'" x-cloak>
        <x-input-label value="Message variants (optional)" />
        <p class="text-xs text-gray-500 mb-2">Alternative wordings — each send rotates between your main message and these.</p>
        <div class="space-y-2">
            <template x-for="(v, i) in variants" :key="i">
                <div class="flex items-start gap-2">
                    <textarea name="variants[]" x-model="variants[i]" rows="2" :placeholder="'Variant ' + (i + 1)"
                              class="flex-1 rounded-lg border-gray-300 text-sm focus:ring-green-500 focus:border-green-500"></textarea>
                    <button type="button" @click="variants.splice(i, 1)" class="text-red-500 px-2 mt-2">&times;</button>
                </div>
            </template>
        </div>
        <button type="button" @click="variants.push('')" class="mt-2 text-sm text-green-600 font-medium">+ Add variant</button>
    </div>

    {{-- Media --}}
    <div x-show="type === 'media'" x-cloak class="grid grid-cols-1 sm:grid-cols-3 gap-4">
        <div>
            <x-input-label for="media_type" value="Media type" />
            <select id="media_type" name="media_type" class="mt-1 block w-full rounded-lg border-gray-300 text-sm focus:ring-green-500 focus:border-green-500">
                @foreach (['image', 'video', 'document', 'audio'] as $mt)
                    <option value="{{ $mt }}" @selected(old('media_type', $template->media_type ?? 'image') === $mt)>{{ ucfirst($mt) }}</option>
                @endforeach
            </select>
        </div>
        <div class="sm:col-span-2">
            <x-input-label for="media_url" value="Media URL" />
            <div class="flex items-center gap-2 mt-1">
                <x-text-input id="media_url" name="media_url" x-ref="mediaUrl" class="block w-full" placeholder="https://…" :value="old('media_url', $template->media_url ?? '')" />
                <label class="shrink-0 cursor-pointer inline-flex items-center gap-1 rounded-lg border border-gray-300 px-3 py-2 text-sm text-gray-600 hover:bg-gray-50">
                    <span x-text="uploading ? 'Uploading…' : 'Upload'"></span>
                    <input type="file" class="hidden" @change="upload($event, url => $refs.mediaUrl.value = url)">
                </label>
            </div>
        </div>
    </div>

    {{-- Poll --}}
    <div x-show="type === 'poll'" x-cloak class="space-y-3">
        <div>
            <x-input-label for="poll_question" value="Poll question" />
            <x-text-input id="poll_question" name="poll_question" class="block mt-1 w-full" :value="old('poll_question', data_get($template, 'poll.question', ''))" />
        </div>
        <div>
            <x-input-label value="Options" />
            <div class="space-y-2 mt-1">
                <template x-for="(option, i) in options" :key="i">
                    <div class="flex items-center gap-2">
                        <input type="text" name="poll_options[]" x-model="options[i]"
                               class="flex-1 rounded-lg border-gray-300 text-sm focus:ring-green-500 focus:border-green-500"
                               :placeholder="'Option ' + (i + 1)">
                        <button type="button" @click="removeOption(i)" x-show="options.length > 2"
                                class="text-red-500 hover:text-red-700 px-2">&times;</button>
                    </div>
                </template>
            </div>
            <button type="button" @click="addOption()" class="mt-2 text-sm text-green-600 font-medium">+ Add option</button>
        </div>
        <label class="inline-flex items-center gap-2">
            <input type="checkbox" name="poll_multiple" value="1" @checked(old('poll_multiple', data_get($template, 'poll.multiple', false)))
                   class="rounded border-gray-300 text-green-600 focus:ring-green-500">
            <span class="text-sm text-gray-700">Allow multiple answers</span>
        </label>

        <div>
            <x-input-label value="Poll image / media (optional)" />
            <div class="grid grid-cols-3 gap-3 mt-1">
                <select name="poll_media_type" x-model="pollMediaType" class="rounded-lg border-gray-300 text-sm">
                    <option value="image">Image</option>
                    <option value="video">Video</option>
                    <option value="document">Document</option>
                    <option value="audio">Audio</option>
                </select>
                <div class="col-span-2 flex items-center gap-2">
                    <input type="text" name="poll_media_url" x-model="pollMediaUrl" placeholder="https://…"
                           class="flex-1 min-w-0 rounded-lg border-gray-300 text-sm focus:ring-green-500 focus:border-green-500">
                    <label class="shrink-0 cursor-pointer inline-flex items-center gap-1 rounded-lg border border-gray-300 px-3 py-2 text-sm text-gray-600 hover:bg-gray-50">
                        <span x-text="uploading ? '…' : 'Upload'"></span>
                        <input type="file" class="hidden" @change="upload($event, url => pollMediaUrl = url)">
                    </label>
                </div>
            </div>
            <p class="text-xs text-gray-500 mt-1">Sent as a separate message right before the poll.</p>
        </div>
    </div>

    {{-- Buttons --}}
    <div x-show="type === 'buttons'" x-cloak class="space-y-3">
        <div class="grid grid-cols-2 gap-3">
            <div>
                <x-input-label value="Title" />
                <input type="text" name="buttons_title" x-model="buttonsTitle" class="mt-1 block w-full rounded-lg border-gray-300 text-sm focus:ring-green-500 focus:border-green-500">
            </div>
            <div>
                <x-input-label value="Footer (optional)" />
                <input type="text" name="buttons_footer" x-model="buttonsFooter" class="mt-1 block w-full rounded-lg border-gray-300 text-sm focus:ring-green-500 focus:border-green-500">
            </div>
        </div>
        <div>
            <x-input-label value="Buttons (up to 3)" />
            <div class="space-y-2 mt-1">
                <template x-for="(b, i) in buttons" :key="i">
                    <div class="flex items-center gap-2">
                        <select x-model="buttons[i].type" :name="'buttons[' + i + '][type]'" class="rounded-lg border-gray-300 text-sm w-24 shrink-0">
                            <option value="reply">Reply</option>
                            <option value="url">Link</option>
                            <option value="call">Call</option>
                        </select>
                        <input type="text" x-model="buttons[i].text" :name="'buttons[' + i + '][text]'" placeholder="Button text"
                               class="flex-1 min-w-0 rounded-lg border-gray-300 text-sm focus:ring-green-500 focus:border-green-500">
                        <input type="text" x-model="buttons[i].value" :name="'buttons[' + i + '][value]'" x-show="buttons[i].type !== 'reply'"
                               :placeholder="buttons[i].type === 'call' ? 'Phone' : 'https://…'"
                               class="flex-1 min-w-0 rounded-lg border-gray-300 text-sm focus:ring-green-500 focus:border-green-500">
                        <button type="button" @click="buttons.splice(i, 1)" x-show="buttons.length > 1" class="text-red-500 px-2">&times;</button>
                    </div>
                </template>
            </div>
            <button type="button" @click="if (buttons.length < 3) buttons.push({ type: 'reply', text: '', value: '' })" class="mt-2 text-sm text-green-600 font-medium">+ Add button</button>
        </div>
        <div class="rounded-lg bg-yellow-50 border border-yellow-200 text-yellow-800 text-xs p-3">
            WhatsApp restricts interactive buttons on the free (Baileys) connection — they may arrive as plain text on some devices.
        </div>
    </div>

    {{-- Carousel cards --}}
    <div x-show="type === 'carousel'" x-cloak class="space-y-3">
        <p class="text-xs text-gray-500">Up to 10 cards. Each is delivered as an image with its caption; card buttons appear as text links.</p>
        <template x-for="(card, ci) in cards" :key="ci">
            <div class="rounded-lg border border-gray-200 p-3 space-y-2">
                <div class="flex items-center justify-between">
                    <span class="text-xs font-semibold text-gray-500" x-text="'Card ' + (ci + 1)"></span>
                    <button type="button" @click="cards.splice(ci, 1)" x-show="cards.length > 1" class="text-red-500 text-xs">Remove</button>
                </div>
                <div class="flex items-center gap-2">
                    <input type="text" x-model="card.image" :name="'cards[' + ci + '][image]'" placeholder="Image URL (https://…)" class="flex-1 min-w-0 rounded-lg border-gray-300 text-sm focus:ring-green-500 focus:border-green-500">
                    <label class="shrink-0 cursor-pointer inline-flex items-center gap-1 rounded-lg border border-gray-300 px-3 py-2 text-sm text-gray-600 hover:bg-gray-50">
                        <span x-text="uploading ? '…' : 'Upload'"></span>
                        <input type="file" class="hidden" @change="upload($event, url => card.image = url)">
                    </label>
                </div>
                <input type="text" x-model="card.title" :name="'cards[' + ci + '][title]'" placeholder="Card title" class="block w-full rounded-lg border-gray-300 text-sm focus:ring-green-500 focus:border-green-500">
                <textarea x-model="card.body" :name="'cards[' + ci + '][body]'" rows="2" placeholder="Card text" class="block w-full rounded-lg border-gray-300 text-sm focus:ring-green-500 focus:border-green-500"></textarea>
                <div class="space-y-2">
                    <template x-for="(b, bi) in card.buttons" :key="bi">
                        <div class="flex items-center gap-2">
                            <select x-model="b.type" :name="'cards[' + ci + '][buttons][' + bi + '][type]'" class="rounded-lg border-gray-300 text-sm w-20 shrink-0">
                                <option value="url">Link</option>
                                <option value="reply">Reply</option>
                            </select>
                            <input type="text" x-model="b.text" :name="'cards[' + ci + '][buttons][' + bi + '][text]'" placeholder="Button text" class="flex-1 min-w-0 rounded-lg border-gray-300 text-sm">
                            <input type="text" x-model="b.value" :name="'cards[' + ci + '][buttons][' + bi + '][value]'" x-show="b.type === 'url'" placeholder="https://…" class="flex-1 min-w-0 rounded-lg border-gray-300 text-sm">
                            <button type="button" @click="card.buttons.splice(bi, 1)" class="text-red-500 px-1">&times;</button>
                        </div>
                    </template>
                    <button type="button" @click="addCardButton(ci)" x-show="card.buttons.length < 2" class="text-xs text-green-600 font-medium">+ button</button>
                </div>
            </div>
        </template>
        <button type="button" @click="addCard()" x-show="cards.length < 10" class="text-sm text-green-600 font-medium">+ Add card</button>
    </div>
</div>
