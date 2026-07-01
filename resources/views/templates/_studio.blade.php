@php
    $mode   = $mode ?? 'create';
    $action = $action ?? route('templates.store');
    $template = $template ?? new \App\Models\Template(['type' => 'text']);
@endphp

<div x-data="templateEditor()" class="grid grid-cols-1 lg:grid-cols-5 gap-6">

    {{-- LEFT: editor --}}
    <div class="lg:col-span-3">
        <form method="POST" action="{{ $action }}" class="space-y-5">
            @csrf
            @if ($mode === 'edit') @method('PUT') @endif

            <x-card title="Message type">
                <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-5 gap-3">
                    @foreach ([['text','Text'], ['media','Image / Video / File'], ['poll','Poll'], ['buttons','Buttons'], ['carousel','Carousel']] as [$val, $title])
                        <button type="button" @click="type = '{{ $val }}'"
                                :class="type === '{{ $val }}' ? 'border-green-500 bg-green-50 text-green-700' : 'border-gray-200 text-gray-600'"
                                class="rounded-xl border p-3 text-sm font-medium text-center transition">
                            {{ $title }}
                        </button>
                    @endforeach
                </div>
                <input type="hidden" name="type" :value="type">
            </x-card>

            <x-card title="Content">
                <div class="space-y-4">
                    <div>
                        <x-input-label for="name" value="Template name" />
                        <x-text-input id="name" name="name" class="block mt-1 w-full" x-model="name" required />
                    </div>

                    <div>
                        <x-input-label for="body">
                            <span x-text="type === 'media' ? 'Caption' : (type === 'buttons' ? 'Description' : 'Message')"></span>
                        </x-input-label>
                        <div class="mt-1">@include('templates._message-toolbar', ['target' => 'body'])</div>
                        <textarea id="body" name="body" rows="5" x-model="body"
                                  class="block w-full rounded-lg border-gray-300 text-sm focus:ring-green-500 focus:border-green-500"
                                  placeholder="Hi @{{name}}, ..."></textarea>
                        @include('templates._spam-live', ['target' => 'body'])
                    </div>

                    {{-- Message variants (A/B copy rotation) --}}
                    <div x-show="type !== 'poll'" x-cloak>
                        <x-input-label value="Message variants (optional)" />
                        <p class="text-xs text-gray-500 mb-2">Add alternative wordings — type them, <strong>✨ Generate</strong> with AI, or <strong>⬆ Import</strong> a list you wrote elsewhere (.txt / .csv / .md, one per line). Each message rotates through your main message and these in turn — keeps copy fresh.</p>

                        <div class="flex items-center gap-2 mb-3 flex-wrap rounded-lg bg-gray-50 border border-gray-200 px-3 py-2">
                            <span class="text-xs text-gray-600">Auto-write</span>
                            <input type="number" x-model="variantCount" min="1" max="20" class="w-16 rounded-lg border-gray-300 text-sm py-1">
                            <span class="text-xs text-gray-600">variants of the message above:</span>
                            <span class="ml-auto flex items-center gap-3">
                                {{-- Import ready-made variants from a file (e.g. written elsewhere with any AI) --}}
                                <label class="text-sm font-medium text-brand cursor-pointer whitespace-nowrap"
                                       title="Import variants from a .txt, .csv or .md file — one variant per line">
                                    ⬆ Import
                                    <input type="file" accept=".txt,.csv,.md,.text,text/plain,text/csv" class="hidden" @change="importVariants($event)">
                                </label>
                                <button type="button" @click="generateVariants()" :disabled="variantGenerating"
                                        class="text-sm font-medium text-brand disabled:opacity-50">
                                    <span x-show="!variantGenerating">✨ Generate</span>
                                    <span x-show="variantGenerating" x-cloak>Generating…</span>
                                </button>
                            </span>
                        </div>

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
                    <div x-show="type === 'media'" x-cloak class="grid grid-cols-3 gap-3">
                        <div>
                            <x-input-label for="media_type" value="Media type" />
                            <select id="media_type" name="media_type" x-model="mediaType" class="mt-1 block w-full rounded-lg border-gray-300 text-sm">
                                @foreach (['image','video','document','audio'] as $mt)
                                    <option value="{{ $mt }}">{{ ucfirst($mt) }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-span-2">
                            <x-input-label for="media_url" value="Upload a file or paste a URL" />
                            <div class="flex gap-2 mt-1">
                                <x-text-input id="media_url" name="media_url" class="block w-full" x-model="mediaUrl" placeholder="https://…" />
                                <label class="shrink-0 inline-flex items-center px-3 rounded-lg border border-gray-300 text-sm cursor-pointer hover:bg-gray-50" :class="uploading && 'opacity-50'">
                                    <input type="file" class="hidden" x-on:change="upload($event, u => mediaUrl = u)">
                                    <span x-text="uploading ? '…' : 'Upload'"></span>
                                </label>
                            </div>
                        </div>
                    </div>

                    {{-- Poll --}}
                    <div x-show="type === 'poll'" x-cloak class="space-y-3">
                        <div>
                            <x-input-label for="poll_question" value="Poll question" />
                            <x-text-input id="poll_question" name="poll_question" class="block mt-1 w-full" x-model="pollQuestion" />
                        </div>
                        <div class="space-y-2">
                            <template x-for="(o, i) in options" :key="i">
                                <div class="flex items-center gap-2">
                                    <input type="text" name="poll_options[]" x-model="options[i]" :placeholder="'Option ' + (i+1)"
                                           class="flex-1 rounded-lg border-gray-300 text-sm focus:ring-green-500 focus:border-green-500">
                                    <button type="button" @click="removeOption(i)" x-show="options.length > 2" class="text-red-500 px-2">&times;</button>
                                </div>
                            </template>
                            <button type="button" @click="options.push('')" class="text-sm text-green-600 font-medium">+ Add option</button>
                        </div>
                        <label class="inline-flex items-center gap-2">
                            <input type="checkbox" name="poll_multiple" value="1" @checked(old('poll_multiple', data_get($template, 'poll.multiple', false))) class="rounded border-gray-300 text-green-600 focus:ring-green-500">
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
                                <div class="col-span-2 flex gap-2">
                                    <input type="text" name="poll_media_url" x-model="pollMediaUrl" placeholder="https://…"
                                           class="flex-1 min-w-0 rounded-lg border-gray-300 text-sm focus:ring-green-500 focus:border-green-500">
                                    <label class="shrink-0 inline-flex items-center px-3 rounded-lg border border-gray-300 text-sm cursor-pointer hover:bg-gray-50">
                                        <input type="file" class="hidden" x-on:change="upload($event, u => pollMediaUrl = u)">
                                        <span x-text="uploading ? '…' : 'Upload'"></span>
                                    </label>
                                </div>
                            </div>
                            <p class="text-xs text-gray-500 mt-1">Your <strong>Message</strong> above is sent with this image as its caption, then the poll appears below it.</p>
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
                            Heads-up: WhatsApp restricts interactive buttons on the free (Baileys) connection — they may arrive as plain text on some devices. Fully reliable buttons need the official WhatsApp Cloud API.
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
                                <div class="flex gap-2">
                                    <input type="text" x-model="card.image" :name="'cards[' + ci + '][image]'" placeholder="Image URL or upload →" class="flex-1 min-w-0 rounded-lg border-gray-300 text-sm focus:ring-green-500 focus:border-green-500">
                                    <label class="shrink-0 inline-flex items-center px-3 rounded-lg border border-gray-300 text-sm cursor-pointer hover:bg-gray-50">
                                        <input type="file" class="hidden" x-on:change="upload($event, u => card.image = u)">
                                        <span x-text="uploading ? '…' : 'Upload'"></span>
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

                    <div class="rounded-lg bg-blue-50 border border-blue-200 text-blue-800 text-xs p-3">
                        <p class="font-medium mb-1">Personalisation &amp; variation</p>
                        <p>Spintax: <code>{Hi|Hello|Good morning}</code> picks one at random per message.</p>
                        <p>Merge tags: <code>@{{name}}</code>, <code>@{{phone}}</code>, <code>@{{date}}</code> are replaced per contact.</p>
                    </div>
                </div>

                <div class="mt-6 flex items-center gap-3">
                    <x-btn type="submit" variant="primary">{{ $mode === 'edit' ? 'Update template' : 'Save template' }}</x-btn>
                    <x-btn :href="route('templates.index')" variant="ghost">Cancel</x-btn>
                </div>
            </x-card>
        </form>
    </div>

    {{-- RIGHT: live WhatsApp preview --}}
    <div class="lg:col-span-2">
        <div class="lg:sticky lg:top-20">
            <div class="mx-auto max-w-xs rounded-[2rem] border-8 border-gray-800 bg-[#e5ddd5] shadow-xl overflow-hidden">
                <div class="bg-[#075e54] text-white px-4 py-3 flex items-center gap-2">
                    <span class="w-8 h-8 rounded-full bg-white/20 grid place-items-center text-sm">A</span>
                    <div class="text-sm leading-tight"><p class="font-medium">Your business</p><p class="text-[10px] text-white/70">online</p></div>
                </div>
                <div class="p-3 min-h-[22rem] space-y-2"
                     style="background-image:url('data:image/svg+xml;utf8,<svg xmlns=%22http://www.w3.org/2000/svg%22 width=%2240%22 height=%2240%22><circle cx=%221%22 cy=%221%22 r=%221%22 fill=%22%23000%22 opacity=%220.03%22/></svg>')">
                    {{-- text / caption bubble (also shows for a poll's accompanying message) --}}
                    <div class="ml-auto max-w-[85%] bg-[#dcf8c6] rounded-lg rounded-tr-none px-3 py-2 shadow-sm"
                         x-show="type !== 'carousel' && (type !== 'poll' || body.trim() || pollMediaUrl)">
                        {{-- media preview: image / video / audio / document --}}
                        <template x-if="hasMedia() && mediaKind() === 'image'">
                            <img :src="mediaSrc()" class="mb-2 rounded w-full max-h-40 object-cover" x-on:error="$el.style.display='none'">
                        </template>
                        <template x-if="hasMedia() && mediaKind() === 'video'">
                            <video :src="mediaSrc()" class="mb-2 rounded w-full max-h-40 bg-black" controls preload="metadata"></video>
                        </template>
                        <template x-if="hasMedia() && mediaKind() === 'audio'">
                            <audio :src="mediaSrc()" class="mb-2 w-full" controls preload="metadata"></audio>
                        </template>
                        <template x-if="hasMedia() && mediaKind() === 'document'">
                            <a :href="mediaSrc()" target="_blank" rel="noopener" class="mb-2 flex items-center gap-2 rounded bg-black/5 px-3 py-2 text-xs text-gray-700 hover:bg-black/10">
                                <svg class="w-7 h-7 text-red-500 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                                <span class="truncate" x-text="mediaSrc().split('/').pop().split('?')[0] || 'Document'"></span>
                            </a>
                        </template>
                        <template x-if="type === 'media' && ! mediaUrl">
                            <div class="mb-2 rounded bg-black/5 h-24 grid place-items-center text-gray-400 text-xs" x-text="mediaType + ' attachment'"></div>
                        </template>
                        <p class="text-sm text-gray-800 whitespace-pre-line break-words" x-text="rendered() || (hasMedia() ? '' : 'Your message preview…')"></p>
                        <p class="text-[10px] text-gray-500 text-right mt-1">12:00 ✓✓</p>
                    </div>
                    {{-- poll bubble --}}
                    <div class="ml-auto max-w-[85%] bg-[#dcf8c6] rounded-lg rounded-tr-none px-3 py-2 shadow-sm" x-show="type === 'poll'" x-cloak>
                        <p class="text-sm font-medium text-gray-800 break-words" x-text="pollQuestion || 'Poll question'"></p>
                        <div class="mt-2 space-y-1">
                            <template x-for="(o, i) in options" :key="i">
                                <div class="flex items-center gap-2 text-sm text-gray-700">
                                    <span class="w-3 h-3 rounded-full border border-gray-400"></span>
                                    <span x-text="o || ('Option ' + (i+1))"></span>
                                </div>
                            </template>
                        </div>
                        <p class="text-[10px] text-gray-500 text-right mt-1">12:00 ✓✓</p>
                    </div>

                    {{-- carousel gallery (swipeable) --}}
                    <div x-show="type === 'carousel'" x-cloak class="-mx-3 px-3 overflow-x-auto flex gap-2 snap-x pb-1">
                        <template x-for="(card, ci) in cards" :key="ci">
                            <div class="snap-start shrink-0 w-40 bg-white rounded-lg shadow-sm overflow-hidden border border-gray-100">
                                <template x-if="card.image">
                                    <img :src="card.image" class="w-full h-24 object-cover" x-on:error="$el.style.display='none'">
                                </template>
                                <template x-if="!card.image">
                                    <div class="w-full h-24 bg-gray-100 grid place-items-center text-gray-300 text-xs">image</div>
                                </template>
                                <div class="p-2">
                                    <p class="text-xs font-semibold text-gray-800 truncate" x-text="card.title || ('Card ' + (ci + 1))"></p>
                                    <p class="text-[11px] text-gray-500 line-clamp-2" x-text="card.body"></p>
                                    <template x-for="(b, bi) in card.buttons" :key="bi">
                                        <div class="mt-1 text-[11px] text-center text-sky-600 border-t border-gray-100 pt-1" x-text="b.text || 'Button'"></div>
                                    </template>
                                </div>
                            </div>
                        </template>
                    </div>
                </div>
            </div>
            <p class="text-center text-xs text-gray-400 mt-3">Live preview — updates as you type</p>
        </div>
    </div>
</div>

@push('scripts')
<script>
    function templateEditor() {
        return {
            type: @js(old('type', $template->type ?? 'text')),
            name: @js(old('name', $template->name ?? '')),
            body: @js(old('body', $template->body ?? '')),
            mediaType: @js(old('media_type', $template->type === 'media' ? ($template->media_type ?? 'image') : 'image')),
            mediaUrl: @js(old('media_url', $template->type === 'media' ? ($template->media_url ?? '') : '')),
            pollQuestion: @js(old('poll_question', data_get($template, 'poll.question', ''))),
            options: @js(old('poll_options', array_values((array) data_get($template, 'poll.options', ['', ''])) ?: ['', ''])),
            variants: @js(old('variants', array_values((array) ($template->variants ?? [])))),
            pollMediaUrl: @js(old('poll_media_url', $template->type === 'poll' ? ($template->media_url ?? '') : '')),
            pollMediaType: @js(old('poll_media_type', $template->type === 'poll' ? ($template->media_type ?? 'image') : 'image')),
            buttonsTitle: @js(old('buttons_title', data_get($template, 'buttons.title', ''))),
            buttonsFooter: @js(old('buttons_footer', data_get($template, 'buttons.footer', ''))),
            buttons: @js(old('buttons', data_get($template, 'buttons.items') ?: [['type' => 'reply', 'text' => '', 'value' => '']])),
            cards: @js(old('cards', $template->cards ?: [['image' => '', 'title' => '', 'body' => '', 'buttons' => []]])),
            variantCount: 10,
            variantGenerating: false,
            uploading: false,
            generateVariants() {
                if (! this.body.trim()) { alert('Write your main message first.'); return; }
                this.variantGenerating = true;
                fetch('{{ route('templates.variants') }}', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content,
                    },
                    body: JSON.stringify({ message: this.body, count: parseInt(this.variantCount) || 10 }),
                })
                .then(r => r.json())
                .then(d => {
                    this.variantGenerating = false;
                    if (d.error) { alert(d.error); return; }
                    this.variants = d.variants || [];
                })
                .catch(() => { this.variantGenerating = false; alert('Could not generate variants.'); });
            },
            importVariants(e) {
                const file = e.target.files[0];
                if (! file) return;
                if (/\.(xlsx|xls)$/i.test(file.name)) {
                    alert('Excel (.xlsx) can’t be read directly. In Excel choose “Save As → CSV” (or .txt) with one variant per row, then Import that file.');
                    e.target.value = '';
                    return;
                }
                const reader = new FileReader();
                reader.onload = () => {
                    const lines = String(reader.result)
                        .split(/\r\n|\r|\n/)
                        .map(l => l.trim())
                        .filter(l => l.length)
                        .map(l => {
                            // Unwrap a quoted CSV cell:  "text with ""quotes"""
                            if (l.startsWith('"') && l.endsWith('"')) {
                                l = l.slice(1, -1).replace(/""/g, '"');
                            }
                            return l.trim();
                        })
                        .filter(l => l.length)
                        .slice(0, 50);
                    if (! lines.length) { alert('No variants found — put one variant per line in the file.'); return; }
                    this.variants = lines;
                };
                reader.onerror = () => alert('Could not read that file.');
                reader.readAsText(file);
                e.target.value = ''; // let the same file be re-imported
            },
            addCard() { if (this.cards.length < 10) this.cards.push({ image: '', title: '', body: '', buttons: [] }); },
            addCardButton(ci) { if (this.cards[ci].buttons.length < 2) this.cards[ci].buttons.push({ type: 'url', text: '', value: '' }); },
            upload(e, setter) {
                const file = e.target.files[0];
                if (! file) return;
                this.uploading = true;
                const fd = new FormData();
                fd.append('file', file);
                fetch('{{ route('uploads.store') }}', {
                    method: 'POST',
                    headers: { 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content, 'Accept': 'application/json' },
                    body: fd,
                })
                .then(r => r.json())
                .then(d => { this.uploading = false; d.url ? setter(d.url) : alert(d.message || 'Upload failed'); })
                .catch(() => { this.uploading = false; alert('Upload failed'); });
                e.target.value = '';
            },
            removeOption(i) { if (this.options.length > 2) this.options.splice(i, 1); },
            mediaSrc() { return this.type === 'poll' ? this.pollMediaUrl : this.mediaUrl; },
            mediaKind() { return this.type === 'poll' ? this.pollMediaType : this.mediaType; },
            hasMedia() { return (this.type === 'media' || this.type === 'poll') && !! this.mediaSrc(); },
            // Resolve spintax (pick first choice) + merge tags for a readable preview.
            rendered() {
                let t = this.body || '';
                t = t.replace(/\{([^{}]*)\}/g, (m, g) => g.split('|')[0]);
                const today = new Date().toLocaleDateString(undefined, { year: 'numeric', month: 'short', day: 'numeric' });
                t = t.replace(/\{\{\s*name\s*\}\}/gi, 'Aisha')
                     .replace(/\{\{\s*phone\s*\}\}/gi, '+9715xxxxxxx')
                     .replace(/\{\{\s*date\s*\}\}/gi, today);
                return t;
            },
        };
    }
</script>
@endpush
