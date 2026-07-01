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

    <template x-if="flagged.length">
        <div class="mt-2">
            <p class="text-[11px] text-gray-500 mb-1">Words raising your score — click to swap for a cleaner word:</p>
            <div class="flex flex-wrap gap-1">
                <template x-for="(f, i) in flagged" :key="i">
                    <button type="button" @click="replaceWord(f)"
                            class="px-2 py-0.5 rounded-full bg-red-100 text-red-700 text-xs hover:bg-red-200"
                            :title="f.alt ? ('Replace with: ' + f.alt) : 'Consider removing this'">
                        <span x-text="f.word"></span><span x-show="f.alt" class="text-red-400" x-text="' → ' + f.alt"></span>
                    </button>
                </template>
            </div>
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
                score: 0, level: 'low', flagged: [], tips: [], ta: null,
                get barColor() { return this.level === 'high' ? 'bg-red-500' : (this.level === 'medium' ? 'bg-yellow-500' : 'bg-green-500'); },
                get textColor() { return this.level === 'high' ? 'text-red-600' : (this.level === 'medium' ? 'text-yellow-700' : 'text-green-600'); },
                init() {
                    this.ta = document.getElementById(targetId);
                    if (! this.ta) return;
                    const run = () => { const r = window.eagleSpamScore(this.ta.value); this.score = r.score; this.level = r.level; this.flagged = r.flagged; this.tips = r.tips; };
                    this.ta.addEventListener('input', run);
                    run();
                },
                replaceWord(f) {
                    if (! f.alt || ! this.ta) return;
                    const re = new RegExp(f.word.replace(/[.*+?^${}()|[\]\\]/g, '\\$&'), 'ig');
                    this.ta.value = this.ta.value.replace(re, f.alt);
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

                    // Spam-trigger words → cleaner alternative ('' = just remove it).
                    const SPAM = {
                        'free': 'complimentary', '100% free': 'included', 'free gift': 'gift', 'winner': '', 'you won': '',
                        'congratulations': '', 'urgent': '', 'act now': '', 'click here': 'tap the link', 'click below': 'the link below',
                        'limited time': '', 'limited offer': '', 'offer expires': '', 'exclusive deal': 'special offer', 'discount': 'special price',
                        'cash': '', 'prize': '', 'guarantee': '', 'guaranteed': '', 'risk free': '', 'no cost': '', 'no obligation': '',
                        'buy now': 'have a look', 'order now': 'order', 'cheap': 'affordable', 'lowest price': 'great price', 'credit': '',
                        'loan': '', 'debt': '', 'casino': '', 'lottery': '', 'jackpot': '', 'bitcoin': '', 'crypto': '', 'investment': '',
                        'earn money': '', 'make money': '', 'work from home': '', 'double your': '', 'get rich': '', 'weight loss': '',
                        'miracle': '', 'hurry': '', 'instant': '', 'claim now': '', 'verify your account': '', 'suspended': '', 'reward': '',
                    };
                    for (const w in SPAM) { if (lower.includes(w)) flagged.push({ word: w, alt: SPAM[w] }); }
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
    </script>
    @endpush
@endonce
