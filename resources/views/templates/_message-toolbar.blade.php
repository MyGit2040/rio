@php
    $target = $target ?? 'body';
    // Ready-made greeting / phrase spintax — one is picked at random per message.
    $spins = [
        'Hi / Dear / Greetings'  => '{Hi|Dear|Greetings|Warm Greetings|Good Day}',
        'Hi / Hello / Hey'       => '{Hi|Hello|Hey}',
        'Good morning / day'     => '{Good morning|Good day|Good afternoon}',
        'Dear / Greetings'       => '{Dear|Good day|Greetings|Warm greetings}',
        'Hope you are well'      => '{Hope you are doing well|Hope you are keeping well|I hope this message finds you well}',
        'Thank you'              => '{Thanks|Thank you|Many thanks|Much appreciated}',
        'Just checking in'       => '{Just checking in|Following up|Touching base|Circling back}',
        'Let me know'            => '{Let me know|Feel free to reach out|Do get in touch|Reach out anytime}',
        'Sign-off'               => '{Best regards|Kind regards|Warm regards|Cheers}',
    ];
    $btn = 'inline-flex items-center justify-center h-8 rounded-md border border-gray-200 bg-white text-gray-700 hover:bg-gray-100 hover:border-gray-300 transition';
@endphp

<div class="msg-toolbar flex flex-wrap items-center gap-1.5 mb-2 rounded-lg border border-gray-200 bg-gray-50 px-2 py-1.5"
     data-target="{{ $target }}">

    {{-- Formatting (WhatsApp markup) --}}
    <button type="button" data-msg-tool data-wrap="*" title="Bold  *text*" class="{{ $btn }} w-8 font-bold">B</button>
    <button type="button" data-msg-tool data-wrap="_" title="Italic  _text_" class="{{ $btn }} w-8 italic">I</button>
    <button type="button" data-msg-tool data-wrap="~" title="Strikethrough  ~text~" class="{{ $btn }} w-8 line-through">S</button>
    <button type="button" data-msg-tool data-wrap="```" title="Monospace  ```text```" class="{{ $btn }} w-9 font-mono text-xs">&lt;/&gt;</button>
    <button type="button" data-msg-tool data-line="&gt; " title="Quote  &gt; text" class="{{ $btn }} w-8 text-base leading-none">&#8220;</button>

    <span class="w-px h-5 bg-gray-300 mx-0.5"></span>

    {{-- Merge tags (replaced per contact) --}}
    <span class="text-[11px] text-gray-400 font-medium pl-0.5">Insert:</span>
    <button type="button" data-msg-tool data-insert="@{{name}}" class="{{ $btn }} px-2.5 text-xs font-medium text-brand">name</button>
    <button type="button" data-msg-tool data-insert="@{{phone}}" class="{{ $btn }} px-2.5 text-xs font-medium text-brand">phone</button>
    <button type="button" data-msg-tool data-insert="@{{date}}" class="{{ $btn }} px-2.5 text-xs font-medium text-brand">date</button>
    <button type="button" data-msg-tool data-insert="@{{variant_ref_id}}" class="{{ $btn }} px-2.5 text-xs font-medium text-brand">ref ID</button>
    <button type="button" data-msg-tool data-insert="@{{random}}" class="{{ $btn }} px-2.5 text-xs font-medium text-brand">random</button>

    <span class="w-px h-5 bg-gray-300 mx-0.5"></span>

    {{-- Greeting / phrase spintax --}}
    <div class="relative" x-data="{ open: false }">
        <button type="button" @click="open = !open" class="{{ $btn }} px-2.5 text-xs font-medium">✨ Greeting ▾</button>
        <div x-show="open" @click.outside="open = false" x-cloak
             class="absolute z-30 mt-1 w-72 bg-white rounded-lg shadow-lg border border-gray-100 py-1 max-h-72 overflow-y-auto">
            <p class="px-3 py-1 text-[11px] text-gray-400">Inserts spintax — one option is used at random per send.</p>
            @foreach ($spins as $label => $spin)
                <button type="button" data-msg-tool data-insert="{{ $spin }}" @click="open = false"
                        class="block w-full text-left px-3 py-1.5 hover:bg-gray-50">
                    <span class="text-sm text-gray-800">{{ $label }}</span>
                    <span class="block text-[11px] text-gray-400 font-mono truncate">{{ $spin }}</span>
                </button>
            @endforeach
            <div class="border-t border-gray-100 mt-1 pt-1">
                <button type="button" data-msg-tool data-insert="{option one|option two|option three}" @click="open = false"
                        class="block w-full text-left px-3 py-1.5 hover:bg-gray-50">
                    <span class="text-sm text-gray-800">Custom spintax</span>
                    <span class="block text-[11px] text-gray-400 font-mono">{a|b|c} — edit the options</span>
                </button>
            </div>
        </div>
    </div>
</div>

@once
    @push('scripts')
    <script>
        (function () {
            function sync(ta) { ta.dispatchEvent(new Event('input', { bubbles: true })); } // keep Alpine x-model in step

            function wrap(ta, mark) {
                const s = ta.selectionStart, e = ta.selectionEnd, v = ta.value;
                const sel = v.slice(s, e) || 'text';
                ta.value = v.slice(0, s) + mark + sel + mark + v.slice(e);
                ta.selectionStart = s + mark.length;
                ta.selectionEnd = s + mark.length + sel.length;
                ta.focus(); sync(ta);
            }
            function insert(ta, text) {
                const s = ta.selectionStart, e = ta.selectionEnd, v = ta.value;
                ta.value = v.slice(0, s) + text + v.slice(e);
                ta.selectionStart = ta.selectionEnd = s + text.length;
                ta.focus(); sync(ta);
            }
            function linePrefix(ta, prefix) {
                const s = ta.selectionStart, v = ta.value;
                const ls = v.lastIndexOf('\n', s - 1) + 1;
                ta.value = v.slice(0, ls) + prefix + v.slice(ls);
                ta.selectionStart = ta.selectionEnd = s + prefix.length;
                ta.focus(); sync(ta);
            }

            document.addEventListener('click', function (ev) {
                const btn = ev.target.closest('[data-msg-tool]');
                if (! btn) return;
                const bar = btn.closest('.msg-toolbar');
                const ta = bar && document.getElementById(bar.dataset.target);
                if (! ta) return;
                ev.preventDefault();
                if (btn.dataset.wrap != null) wrap(ta, btn.dataset.wrap);
                else if (btn.dataset.line != null) linePrefix(ta, btn.dataset.line);
                else if (btn.dataset.insert != null) insert(ta, btn.dataset.insert);
            });
        })();
    </script>
    @endpush
@endonce
