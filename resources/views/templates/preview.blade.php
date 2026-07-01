<x-app-layout>
    <x-slot name="header">Preview — {{ $template->name }}</x-slot>

    @php
        $render = function ($text) {
            $text = (string) $text;
            $text = preg_replace_callback('/\{([^{}]*)\}/', fn ($m) => explode('|', $m[1])[0], $text);
            return preg_replace(
                ['/\{\{\s*name\s*\}\}/i', '/\{\{\s*phone\s*\}\}/i', '/\{\{\s*date\s*\}\}/i'],
                ['Aisha', '+9715xxxxxxx', now()->format('M j, Y')],
                $text
            );
        };
        $body = $render($template->body);
        $mediaUrl = $template->media_url;
        $mediaKind = $template->media_type ?: 'image';
    @endphp

    <div class="mb-4 flex items-center gap-3 flex-wrap">
        <a href="{{ route('templates.index') }}" class="text-sm text-gray-500 hover:text-gray-700">&larr; Templates</a>
        <span class="px-2 py-0.5 rounded-full text-xs bg-gray-100 text-gray-600">{{ ucfirst($template->type) }}</span>
        <x-btn :href="route('templates.edit', $template)" variant="secondary" class="ml-auto">Edit template</x-btn>
    </div>

    <div class="mx-auto max-w-xs rounded-[2rem] border-8 border-gray-800 bg-[#e5ddd5] shadow-xl overflow-hidden">
        <div class="bg-[#075e54] text-white px-4 py-3 flex items-center gap-2">
            <span class="w-8 h-8 rounded-full bg-white/20 grid place-items-center text-sm">A</span>
            <div class="text-sm leading-tight"><p class="font-medium">Your business</p><p class="text-[10px] text-white/70">online</p></div>
        </div>
        <div class="p-3 min-h-[20rem] space-y-2"
             style="background-image:url('data:image/svg+xml;utf8,<svg xmlns=%22http://www.w3.org/2000/svg%22 width=%2240%22 height=%2240%22><circle cx=%221%22 cy=%221%22 r=%221%22 fill=%22%23000%22 opacity=%220.03%22/></svg>')">

            @if ($template->type === 'carousel')
                <div class="-mx-3 px-3 overflow-x-auto flex gap-2 pb-1">
                    @forelse ($template->cards ?? [] as $card)
                        <div class="shrink-0 w-40 bg-white rounded-lg shadow-sm overflow-hidden border border-gray-100">
                            @if (!empty($card['image']))
                                <img src="{{ $card['image'] }}" class="w-full h-24 object-cover" alt="">
                            @else
                                <div class="w-full h-24 bg-gray-100 grid place-items-center text-gray-300 text-xs">image</div>
                            @endif
                            <div class="p-2">
                                <p class="text-xs font-semibold text-gray-800 truncate">{{ $card['title'] ?? '' }}</p>
                                <p class="text-[11px] text-gray-500">{{ Str::limit($card['body'] ?? '', 60) }}</p>
                                @foreach ($card['buttons'] ?? [] as $b)
                                    <div class="mt-1 text-[11px] text-center text-sky-600 border-t border-gray-100 pt-1">{{ $b['text'] ?? 'Button' }}</div>
                                @endforeach
                            </div>
                        </div>
                    @empty
                        <p class="text-xs text-gray-400">No cards.</p>
                    @endforelse
                </div>

            @else
                {{-- message / caption bubble (media + text) --}}
                @if (($template->type === 'media' || $template->type === 'poll') && $mediaUrl)
                    <div class="ml-auto max-w-[85%] bg-[#dcf8c6] rounded-lg rounded-tr-none px-3 py-2 shadow-sm">
                        @if ($mediaKind === 'image')
                            <img src="{{ $mediaUrl }}" class="mb-2 rounded w-full max-h-40 object-cover" alt="">
                        @elseif ($mediaKind === 'video')
                            <video src="{{ $mediaUrl }}" class="mb-2 rounded w-full max-h-40 bg-black" controls preload="metadata"></video>
                        @elseif ($mediaKind === 'audio')
                            <audio src="{{ $mediaUrl }}" class="mb-2 w-full" controls preload="metadata"></audio>
                        @else
                            <a href="{{ $mediaUrl }}" target="_blank" rel="noopener" class="mb-2 flex items-center gap-2 rounded bg-black/5 px-3 py-2 text-xs text-gray-700 hover:bg-black/10">
                                <svg class="w-7 h-7 text-red-500 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                                <span class="truncate">{{ basename(parse_url($mediaUrl, PHP_URL_PATH) ?: 'document') }}</span>
                            </a>
                        @endif
                        @if ($body)<p class="text-sm text-gray-800 whitespace-pre-line break-words">{{ $body }}</p>@endif
                        <p class="text-[10px] text-gray-500 text-right mt-1">12:00 ✓✓</p>
                    </div>
                @elseif ($body !== '' || $template->type === 'buttons')
                    <div class="ml-auto max-w-[85%] bg-[#dcf8c6] rounded-lg rounded-tr-none px-3 py-2 shadow-sm">
                        <p class="text-sm text-gray-800 whitespace-pre-line break-words">{{ $body !== '' ? $body : '—' }}</p>
                        @if ($template->type === 'buttons')
                            <div class="mt-2 space-y-1">
                                @foreach (data_get($template->buttons, 'items', []) as $b)
                                    <div class="text-[13px] text-center text-sky-600 border border-sky-200 rounded py-1">{{ $b['text'] ?? 'Button' }}</div>
                                @endforeach
                            </div>
                        @endif
                        <p class="text-[10px] text-gray-500 text-right mt-1">12:00 ✓✓</p>
                    </div>
                @endif

                {{-- poll bubble --}}
                @if ($template->type === 'poll')
                    <div class="ml-auto max-w-[85%] bg-[#dcf8c6] rounded-lg rounded-tr-none px-3 py-2 shadow-sm">
                        <p class="text-sm font-medium text-gray-800 break-words">{{ data_get($template->poll, 'question', 'Poll') }}</p>
                        <div class="mt-2 space-y-1">
                            @foreach (data_get($template->poll, 'options', []) as $o)
                                <div class="flex items-center gap-2 text-sm text-gray-700"><span class="w-3 h-3 rounded-full border border-gray-400"></span><span>{{ $o }}</span></div>
                            @endforeach
                        </div>
                        <p class="text-[10px] text-gray-500 text-right mt-1">12:00 ✓✓</p>
                    </div>
                @endif
            @endif
        </div>
    </div>

    <p class="text-center text-xs text-gray-400 mt-3">Read-only preview · sample data shown for @{{name}} / @{{phone}} / @{{date}}</p>
</x-app-layout>
