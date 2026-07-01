@php($target = $target ?? 'body')

{{-- Live spam-score panel — evaluates the message as you type, no server round-trip. --}}
<div x-data="spamLive('{{ $target }}')" x-init="init()" x-cloak
     class="mt-2 rounded-lg border border-gray-200 bg-gray-50/70 p-3">
    <div class="flex items-center gap-3">
        <span class="text-xs font-medium text-gray-600 shrink-0">Spam score</span>
        <div class="flex-1 h-2 rounded-full bg-gray-200 overflow-hidden">
            <div class="h-full transition-all" :class="barColor" :style="`width:${score}%`"></div>
        </div>
        <span class="text-xs font-semibold shrink-0" :class="textColor" x-text="score + '/100 · ' + level"></span>
    </div>

    {{-- The message with flagged words shaded red, so you can see them in context. --}}
    <div x-show="flagged.length && highlighted" x-cloak
         class="mt-2 text-xs text-gray-800 whitespace-pre-line break-words leading-relaxed bg-white rounded border border-gray-100 p-2 max-h-32 overflow-auto"
         x-html="highlighted"></div>

    <template x-if="flagged.length">
        <div class="mt-2 space-y-1">
            <p class="text-[11px] text-gray-500">Words raising your score — tap a suggestion to swap it in:</p>
            <template x-for="(f, i) in flagged" :key="i">
                <div class="flex items-center gap-1.5 flex-wrap">
                    <span class="px-2 py-0.5 rounded-full bg-red-100 text-red-700 text-xs" x-text="f.word"></span>
                    <span class="text-gray-400 text-xs" x-show="f.alts.length">→</span>
                    <template x-for="(a, ai) in f.alts" :key="ai">
                        <button type="button" @click="replaceWord(f.word, a)"
                                class="px-2 py-0.5 rounded-full bg-green-100 text-green-700 text-xs hover:bg-green-200" x-text="a"></button>
                    </template>
                    <button type="button" x-show="! f.alts.length" @click="replaceWord(f.word, '')"
                            class="text-[11px] text-gray-500 hover:text-red-600 underline">remove</button>
                </div>
            </template>
        </div>
    </template>

    <template x-if="tips.length">
        <ul class="mt-2 text-[11px] text-gray-500 list-disc list-inside space-y-0.5">
            <template x-for="(t, i) in tips" :key="i"><li x-text="t"></li></template>
        </ul>
    </template>
</div>

@once
    @push('scripts')
    <script>
        function spamLive(targetId) {
            return {
                score: 0, level: 'low', flagged: [], tips: [], highlighted: '', ta: null,
                get barColor() { return this.level === 'high' ? 'bg-red-500' : (this.level === 'medium' ? 'bg-yellow-500' : 'bg-green-500'); },
                get textColor() { return this.level === 'high' ? 'text-red-600' : (this.level === 'medium' ? 'text-yellow-700' : 'text-green-600'); },
                init() {
                    this.ta = document.getElementById(targetId);
                    if (! this.ta) return;
                    const run = () => {
                        const r = window.eagleSpamScore(this.ta.value);
                        this.score = r.score; this.level = r.level; this.flagged = r.flagged; this.tips = r.tips;
                        this.highlighted = window.eagleSpamHighlight(this.ta.value);
                    };
                    this.ta.addEventListener('input', run);
                    run();
                },
                replaceWord(word, alt) {
                    if (! this.ta) return;
                    const re = new RegExp(word.replace(/[.*+?^${}()|[\]\\]/g, '\\$&'), 'ig');
                    this.ta.value = this.ta.value.replace(re, alt);
                    this.ta.dispatchEvent(new Event('input', { bubbles: true })); // re-scores + syncs Alpine x-model
                    this.ta.focus();
                },
            };
        }
        // Shared, instant scorer — used by the main box AND every variant.
        window.eagleSpamScore = function (text) {
                    text = text || '';
                    const lower = text.toLowerCase();
                    let score = 0; const flagged = []; const tips = [];

                    // Spam-trigger words → list of cleaner alternatives ([] = just remove it).
                    const SPAM = {
                        'free': ['complimentary', 'included', 'no charge'], '100% free': ['fully included'], 'free gift': ['gift', 'bonus'],
                        'winner': ['selected'], 'you won': ['you are eligible'], 'congratulations': ['great news'],
                        'urgent': ['important', 'time-sensitive'], 'act now': ['get started', 'have a look'],
                        'click here': ['tap the link', 'open the link'], 'click below': ['the link below'],
                        'limited time': ['for a short while'], 'limited offer': ['current offer'], 'offer expires': ['offer ends'],
                        'exclusive deal': ['special offer', 'members offer'], 'discount': ['special price', 'saving', 'better rate'],
                        'cash': ['payment', 'funds'], 'prize': ['reward', 'gift'], 'guarantee': ['assurance'],
                        'guaranteed': ['assured', 'reliable'], 'risk free': ['no-obligation'], 'no cost': ['included'],
                        'no obligation': ['no commitment'], 'buy now': ['have a look', 'explore'], 'order now': ['order', 'place your order'],
                        'cheap': ['affordable', 'budget-friendly'], 'lowest price': ['great price', 'competitive price'],
                        'credit': [], 'loan': [], 'debt': [], 'casino': [], 'lottery': [], 'jackpot': [], 'bitcoin': [], 'crypto': [],
                        'investment': [], 'earn money': ['grow income'], 'make money': ['increase revenue'], 'work from home': ['remote work'],
                        'double your': ['grow your'], 'get rich': [], 'weight loss': [], 'miracle': ['remarkable'], 'hurry': ['soon'],
                        'instant': ['quick', 'fast'], 'claim now': ['request yours'], 'verify your account': [], 'suspended': [], 'reward': ['benefit', 'perk'],
                    };
                    for (const w in SPAM) { if (lower.includes(w)) flagged.push({ word: w, alts: SPAM[w] || [] }); }
                    if (flagged.length) { score += Math.min(35, flagged.length * 6); tips.push('Reword the salesy terms shown in red.'); }

                    const links = (text.match(/\b(?:https?:\/\/|www\.)\S+/ig) || []).length;
                    if (links) { score += Math.min(30, 15 + (links - 1) * 8); tips.push('Links to people who didn’t opt in are a top spam signal.'); }

                    const caps = (text.match(/\b[A-Z]{3,}\b/g) || []).filter(w => ! /\d/.test(w)).length;
                    if (caps >= 2) { score += 10; tips.push('Avoid writing in ALL CAPS.'); }

                    if (/[!?]{3,}|!{2,}.*!{2,}/.test(text)) { score += 8; tips.push('Use at most one exclamation mark.'); }

                    const emojis = (text.match(/[\u{1F300}-\u{1FAFF}\u{2600}-\u{27BF}]/gu) || []).length;
                    if (emojis > 5) { score += 6; tips.push('Keep emojis to one or two.'); }

                    if (text.trim() && ! /\{\{\s*\w+\s*\}\}/.test(text)) { score += 6; tips.push('Add @{{name}} so each message is personalised.'); }

                    if (/(\$|€|£|aed|usd|inr|rs\.?)\s?\d|\d+%\s?(off|discount)/i.test(text)) { score += 5; }

                    score = Math.min(100, score);
                    const level = score <= 25 ? 'low' : (score <= 55 ? 'medium' : 'high');
                    return { score, level, flagged, tips: tips.slice(0, 3) };
        };
        // Escape text, then wrap each flagged word in a red mark for an at-a-glance view.
        window.eagleSpamHighlight = function (text) {
            text = text || '';
            let html = text.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
            const words = window.eagleSpamScore(text).flagged.map(f => f.word).sort((a, b) => b.length - a.length);
            for (const w of words) {
                const esc = w.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
                html = html.replace(new RegExp('(' + esc + ')(?![^<]*>)', 'ig'), '<mark class="bg-red-200 text-red-800 rounded px-0.5">$1</mark>');
            }
            return html;
        };
    </script>
    @endpush
@endonce
