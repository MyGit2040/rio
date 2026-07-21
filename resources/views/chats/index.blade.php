<x-app-layout>
    <x-slot name="header">Chats</x-slot>

    @if ($devices->isEmpty())
        <x-card>
            <div class="text-center py-12">
                <p class="text-gray-600 font-medium">No WhatsApp numbers linked yet.</p>
                <p class="text-sm text-gray-500 mt-1">Link a number on the Devices page — each one becomes a tab here, like your own multi-account WhatsApp Web.</p>
                <a href="{{ route('devices.index') }}" class="inline-block mt-4 px-4 py-2 rounded-lg bg-brand text-white text-sm font-medium">Go to Devices</a>
            </div>
        </x-card>
    @else
    <div x-data="chatWorkspace()" x-init="init()"
         class="flex flex-col" style="height: calc(100vh - 9.5rem);">

        {{-- Browser-style account tabs: one per linked number --}}
        <div class="flex items-end gap-1 overflow-x-auto pb-0 -mb-px" role="tablist">
            <template x-for="d in devices" :key="d.id">
                <button @click="switchDevice(d.id)" role="tab" :aria-selected="String(d.id === activeDeviceId)"
                        class="flex items-center gap-2 px-4 py-2.5 rounded-t-xl border border-b-0 text-sm whitespace-nowrap min-w-0 max-w-[220px]"
                        :class="d.id === activeDeviceId
                            ? 'bg-white border-gray-200 font-semibold text-gray-800 shadow-sm'
                            : 'bg-gray-100 border-transparent text-gray-500 hover:bg-gray-200'">
                    <span class="w-2 h-2 rounded-full shrink-0"
                          :class="d.status === 'open' ? 'bg-green-500' : 'bg-gray-400'"
                          :title="d.status === 'open' ? 'Connected' : 'Not connected'"></span>
                    <span class="truncate" x-text="d.name"></span>
                    <span x-show="unreadForDevice(d.id) > 0" x-cloak
                          class="ml-1 shrink-0 min-w-[18px] h-[18px] px-1 grid place-items-center rounded-full bg-green-500 text-white text-[10px] font-bold"
                          x-text="unreadForDevice(d.id)"></span>
                </button>
            </template>
        </div>

        <div class="flex-1 min-h-0 bg-white border border-gray-200 rounded-b-xl rounded-tr-xl shadow-sm flex overflow-hidden">

            {{-- Conversation rail --}}
            <div class="w-full lg:w-80 lg:shrink-0 border-r border-gray-100 flex-col min-h-0"
                 :class="mobileThreadOpen ? 'hidden lg:flex' : 'flex'">
                <div class="p-3 border-b border-gray-100 flex items-center gap-2">
                    <input x-model="search" @input.debounce.400ms="loadConversations(activeDeviceId)"
                           placeholder="Search name or number…"
                           class="flex-1 min-w-0 rounded-lg border-gray-300 text-sm focus:ring-green-500 focus:border-green-500">
                    <button @click="newChatOpen = ! newChatOpen" title="New chat"
                            class="shrink-0 grid place-items-center w-9 h-9 rounded-lg bg-brand text-white hover:opacity-90">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
                    </button>
                </div>

                {{-- New chat: type a number, start the thread --}}
                <div x-show="newChatOpen" x-cloak class="p-3 border-b border-gray-100 bg-gray-50">
                    <form @submit.prevent="startNewChat()" class="flex items-center gap-2">
                        <input x-model="newChatPhone" required placeholder="Number with country code, e.g. 9715…"
                               class="flex-1 min-w-0 rounded-lg border-gray-300 text-sm focus:ring-green-500 focus:border-green-500">
                        <button type="submit" class="shrink-0 px-3 py-2 rounded-lg bg-brand text-white text-sm font-medium">Open</button>
                    </form>
                </div>

                <div class="flex-1 overflow-y-auto min-h-0">
                    <template x-if="conversationsLoaded && conversations.length === 0">
                        <p class="px-4 py-10 text-center text-sm text-gray-500">
                            No conversations on this number yet.<br>Start one with the + button above.
                        </p>
                    </template>
                    <template x-for="c in conversations" :key="c.phone">
                        <button @click="openThread(c.phone)"
                                class="w-full flex items-center gap-3 px-4 py-3 text-left hover:bg-gray-50 border-b border-gray-50"
                                :class="activePhone === c.phone ? 'bg-green-50/60' : ''">
                            <span class="grid place-items-center w-10 h-10 rounded-full bg-gray-100 text-gray-600 font-semibold shrink-0"
                                  x-text="(c.name || c.phone || '?').substring(0, 1).toUpperCase()"></span>
                            <span class="min-w-0 flex-1">
                                <span class="flex items-center gap-2">
                                    <span class="font-medium text-gray-800 truncate" x-text="c.name || ('+' + c.phone)"></span>
                                    <span class="ml-auto text-[10px] text-gray-400 whitespace-nowrap shrink-0" x-text="c.last_at"></span>
                                </span>
                                <span class="flex items-center gap-1.5 text-sm text-gray-500">
                                    <span x-show="c.last_direction === 'out'" class="shrink-0 text-gray-400 text-xs">You:</span>
                                    <span class="truncate" x-text="previewOf(c)"></span>
                                    <span x-show="isUnread(c)" x-cloak
                                          class="ml-auto shrink-0 w-2.5 h-2.5 rounded-full bg-green-500"></span>
                                </span>
                            </span>
                        </button>
                    </template>
                </div>

                <div class="px-4 py-2 border-t border-gray-100 text-[11px] text-gray-400 flex items-center gap-2 flex-wrap">
                    <span class="w-2 h-2 rounded-full shrink-0" :class="deviceMeta.status === 'open' ? 'bg-green-500' : 'bg-gray-400'"></span>
                    <span x-text="deviceMeta.status === 'open' ? 'Connected' : 'Not connected'"></span>
                    <span class="ml-auto" x-show="deviceMeta.daily_cap > 0"
                          x-text="'Sent today ' + deviceMeta.sent_today + ' / ' + deviceMeta.daily_cap"></span>
                </div>
            </div>

            {{-- Thread pane --}}
            <div class="flex-1 min-w-0 flex-col min-h-0 bg-gray-50"
                 :class="mobileThreadOpen ? 'flex' : 'hidden lg:flex'">

                <template x-if="! activePhone">
                    <div class="flex-1 grid place-items-center text-center p-8">
                        <div>
                            <p class="text-gray-600 font-medium">Pick a conversation</p>
                            <p class="text-sm text-gray-400 mt-1">Every linked number is a tab above — switch tabs to chat from a different account.</p>
                        </div>
                    </div>
                </template>

                <template x-if="activePhone">
                    <div class="flex-1 min-h-0 flex flex-col">
                        {{-- Thread header --}}
                        <div class="flex items-center gap-3 px-4 py-3 bg-white border-b border-gray-100">
                            <button @click="mobileThreadOpen = false" class="lg:hidden text-gray-500 hover:text-gray-700">
                                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg>
                            </button>
                            <span class="grid place-items-center w-9 h-9 rounded-full bg-gray-100 text-gray-600 font-semibold shrink-0"
                                  x-text="(activeName || activePhone || '?').substring(0, 1).toUpperCase()"></span>
                            <div class="min-w-0">
                                <p class="font-semibold text-gray-800 truncate" x-text="activeName || ('+' + activePhone)"></p>
                                <p class="text-xs text-gray-400 truncate" x-text="'+' + activePhone + ' · via ' + (activeDevice()?.name || '')"></p>
                            </div>
                            <a x-show="activeContactId" x-cloak :href="'{{ url('/contacts') }}/' + activeContactId"
                               class="ml-auto shrink-0 text-sm text-green-600 hover:text-green-700">Profile</a>
                        </div>

                        {{-- Messages --}}
                        <div x-ref="scroller" class="flex-1 min-h-0 overflow-y-auto p-4 space-y-2">
                            <template x-if="threadLoaded && messages.length === 0">
                                <p class="text-center text-sm text-gray-400 py-10">No messages here yet. Say hello 👋</p>
                            </template>
                            <template x-for="m in messages" :key="m.id">
                                <div class="flex" :class="m.direction === 'out' ? 'justify-end' : 'justify-start'">
                                    <div class="max-w-[85%] sm:max-w-md rounded-2xl px-3.5 py-2 text-sm shadow-sm"
                                         :class="m.direction === 'out' ? 'bg-green-600 text-white rounded-br-sm' : 'bg-white text-gray-800 rounded-bl-sm border border-gray-100'">
                                        <p x-show="m.type !== 'text'" class="text-[11px] font-semibold mb-0.5"
                                           :class="m.direction === 'out' ? 'text-green-100' : 'text-gray-400'"
                                           x-text="'📎 ' + m.type"></p>
                                        <p class="whitespace-pre-line break-words" x-text="m.body || (m.type !== 'text' ? '' : '')"></p>
                                        <p class="text-[10px] mt-1 flex items-center gap-1 justify-end"
                                           :class="m.direction === 'out' ? 'text-green-100' : 'text-gray-400'">
                                            <span x-text="m.at"></span>
                                            <span x-show="m.direction === 'out'" x-text="tickFor(m.status)"></span>
                                        </p>
                                    </div>
                                </div>
                            </template>
                        </div>

                        {{-- Composer --}}
                        <div class="p-3 bg-white border-t border-gray-100">
                            <div x-show="attachment" x-cloak class="mb-2 flex items-center gap-2 text-sm bg-gray-50 border border-gray-200 rounded-lg px-3 py-1.5">
                                <span>📎</span>
                                <span class="truncate text-gray-700" x-text="attachment?.name"></span>
                                <button @click="attachment = null" class="ml-auto text-gray-400 hover:text-red-500">✕</button>
                            </div>
                            <p x-show="error" x-cloak class="mb-2 text-sm text-red-700 bg-red-50 border border-red-200 rounded-lg px-3 py-1.5" x-text="error"></p>
                            <template x-if="activeDevice()?.status === 'open'">
                                <form @submit.prevent="sendMessage()" class="flex items-end gap-2">
                                    <input type="file" x-ref="file" class="hidden" @change="uploadAttachment($event)"
                                           accept=".jpg,.jpeg,.png,.webp,.gif,.mp4,.mov,.pdf,.doc,.docx,.xls,.xlsx,.mp3,.ogg,.m4a">
                                    <button type="button" @click="$refs.file.click()" :disabled="uploading" title="Attach a file"
                                            class="shrink-0 grid place-items-center w-10 h-10 rounded-lg text-gray-500 hover:bg-gray-100 disabled:opacity-50">
                                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.172 7l-6.586 6.586a2 2 0 102.828 2.828l6.414-6.586a4 4 0 00-5.656-5.656l-6.415 6.585a6 6 0 108.486 8.486L20.5 13"/></svg>
                                    </button>
                                    <textarea x-model="draft" x-ref="draft" rows="1" placeholder="Type a message…"
                                              @keydown.enter="if (! $event.shiftKey) { $event.preventDefault(); sendMessage(); }"
                                              class="flex-1 min-w-0 rounded-lg border-gray-300 text-sm focus:ring-green-500 focus:border-green-500 resize-none max-h-32"></textarea>
                                    <button type="submit" :disabled="sending || uploading || (! draft.trim() && ! attachment)"
                                            class="shrink-0 grid place-items-center w-10 h-10 rounded-lg bg-brand text-white disabled:opacity-50">
                                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M22 2L11 13M22 2l-7 20-4-9-9-4 20-7z"/></svg>
                                    </button>
                                </form>
                            </template>
                            <template x-if="activeDevice() && activeDevice().status !== 'open'">
                                <p class="text-sm text-amber-700 bg-amber-50 border border-amber-200 rounded-lg px-3 py-2">
                                    This number is not connected. Reconnect it on the
                                    <a href="{{ route('devices.index') }}" class="underline">Devices</a> page to send.
                                </p>
                            </template>
                        </div>
                    </div>
                </template>
            </div>
        </div>
    </div>

    @push('scripts')
    <script>
        // Plain inline script: executes during parsing, before Alpine (a deferred
        // module) boots — the same load-order pattern as window.bulkSelect.
        window.chatWorkspace = function () {
            const cfg = {
                devices: @json($deviceTabs),
                baseUrl: @json(url('/chats')),
                uploadUrl: @json(route('uploads.store')),
                csrf: @json(csrf_token()),
            };

            return {
                devices: cfg.devices,
                activeDeviceId: null,
                conversations: [],
                conversationsLoaded: false,
                convCache: {},            // deviceId -> conversations (drives unread badges on every tab)
                deviceMeta: { status: '', sent_today: 0, daily_cap: 0 },
                search: '',
                newChatOpen: false,
                newChatPhone: '',
                activePhone: null,
                activeName: null,
                activeContactId: null,
                messages: [],
                threadLoaded: false,
                lastMessageId: 0,
                draft: '',
                attachment: null,
                sending: false,
                uploading: false,
                error: '',
                seen: {},                 // 'deviceId:phone' -> last message id read here
                timers: [],

                init() {
                    try { this.seen = JSON.parse(localStorage.getItem('rio-chat-seen') || '{}'); } catch (e) { this.seen = {}; }
                    const remembered = parseInt(localStorage.getItem('rio-chat-device') || '0', 10);
                    const first = this.devices.find(d => d.id === remembered)
                        || this.devices.find(d => d.status === 'open')
                        || this.devices[0];
                    if (first) this.switchDevice(first.id);

                    this.timers.push(setInterval(() => { if (this.activeDeviceId) this.loadConversations(this.activeDeviceId, true); }, 8000));
                    this.timers.push(setInterval(() => this.pollThread(), 4000));
                    this.timers.push(setInterval(() => this.refreshOtherDevices(), 30000));
                    this.refreshOtherDevices();
                },

                activeDevice() { return this.devices.find(d => d.id === this.activeDeviceId) || null; },

                switchDevice(id) {
                    if (this.activeDeviceId === id) return;
                    this.activeDeviceId = id;
                    localStorage.setItem('rio-chat-device', String(id));
                    this.activePhone = null;
                    this.activeName = null;
                    this.activeContactId = null;
                    this.messages = [];
                    this.threadLoaded = false;
                    this.conversations = this.convCache[id] || [];
                    this.conversationsLoaded = !! this.convCache[id];
                    this.error = '';
                    this.loadConversations(id);
                },

                async loadConversations(deviceId, silent = false) {
                    try {
                        const q = deviceId === this.activeDeviceId ? this.search : '';
                        const res = await fetch(`${cfg.baseUrl}/${deviceId}/conversations?q=` + encodeURIComponent(q), {
                            headers: { 'Accept': 'application/json' },
                        });
                        if (! res.ok) return;
                        const data = await res.json();
                        this.convCache[deviceId] = data.conversations;
                        const dev = this.devices.find(d => d.id === deviceId);
                        if (dev) dev.status = data.device.status;
                        if (deviceId === this.activeDeviceId) {
                            this.conversations = data.conversations;
                            this.conversationsLoaded = true;
                            this.deviceMeta = data.device;
                        }
                    } catch (e) { if (! silent) console.error(e); }
                },

                refreshOtherDevices() {
                    this.devices.filter(d => d.id !== this.activeDeviceId)
                        .forEach(d => this.loadConversations(d.id, true));
                },

                async openThread(phone) {
                    this.activePhone = phone;
                    this.mobileThreadOpen = true;
                    this.messages = [];
                    this.threadLoaded = false;
                    this.lastMessageId = 0;
                    this.error = '';
                    const conv = this.conversations.find(c => c.phone === phone);
                    this.activeName = conv ? conv.name : null;
                    this.activeContactId = conv ? conv.contact_id : null;
                    await this.fetchThread(false);
                    this.$nextTick(() => this.scrollToEnd());
                    this.$nextTick(() => this.$refs.draft && this.$refs.draft.focus());
                },

                async fetchThread(incremental) {
                    if (! this.activePhone || ! this.activeDeviceId) return;
                    const params = new URLSearchParams({ phone: this.activePhone });
                    if (incremental && this.lastMessageId) params.set('after_id', String(this.lastMessageId));
                    try {
                        const res = await fetch(`${cfg.baseUrl}/${this.activeDeviceId}/thread?` + params.toString(), {
                            headers: { 'Accept': 'application/json' },
                        });
                        if (! res.ok) return;
                        const data = await res.json();
                        if (data.contact) { this.activeName = data.contact.name; this.activeContactId = data.contact.id; }
                        const incoming = data.messages.filter(m => ! this.messages.some(x => x.id === m.id));
                        if (incoming.length) {
                            this.messages = incremental ? this.messages.concat(incoming) : data.messages;
                            this.$nextTick(() => this.scrollToEnd());
                        } else if (! incremental) {
                            this.messages = data.messages;
                        }
                        if (this.messages.length) this.lastMessageId = this.messages[this.messages.length - 1].id;
                        this.threadLoaded = true;
                        this.markSeen();
                    } catch (e) { /* next poll retries */ }
                },

                pollThread() { if (this.activePhone) this.fetchThread(true); },

                async sendMessage() {
                    const body = this.draft.trim();
                    if ((! body && ! this.attachment) || this.sending) return;
                    this.sending = true;
                    this.error = '';
                    try {
                        const payload = { phone: this.activePhone, body: body || null };
                        if (this.attachment) {
                            payload.media_url = this.attachment.url;
                            payload.media_type = this.attachment.type;
                            payload.media_name = this.attachment.name;
                        }
                        const res = await fetch(`${cfg.baseUrl}/${this.activeDeviceId}/send`, {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': cfg.csrf },
                            body: JSON.stringify(payload),
                        });
                        const data = await res.json().catch(() => ({}));
                        if (! res.ok || ! data.ok) {
                            this.error = data.error || (data.message ?? 'Could not send. Please try again.');
                            return;
                        }
                        this.messages.push(data.message);
                        this.lastMessageId = data.message.id;
                        this.draft = '';
                        this.attachment = null;
                        this.markSeen();
                        this.$nextTick(() => this.scrollToEnd());
                        this.loadConversations(this.activeDeviceId, true);
                    } catch (e) {
                        this.error = 'Network error — the message was not sent.';
                    } finally {
                        this.sending = false;
                    }
                },

                async uploadAttachment(event) {
                    const file = event.target.files[0];
                    if (! file) return;
                    this.uploading = true;
                    this.error = '';
                    try {
                        const form = new FormData();
                        form.append('file', file);
                        const res = await fetch(cfg.uploadUrl, {
                            method: 'POST',
                            headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': cfg.csrf },
                            body: form,
                        });
                        const data = await res.json().catch(() => ({}));
                        if (! res.ok || ! data.url) {
                            this.error = data.message || 'Upload failed. Allowed: images, video, audio, PDF and office files up to 20 MB.';
                            return;
                        }
                        this.attachment = { url: data.url, name: data.name, type: this.mediaTypeOf(file.type, data.name) };
                    } catch (e) {
                        this.error = 'Upload failed — check your connection.';
                    } finally {
                        this.uploading = false;
                        event.target.value = '';
                    }
                },

                mediaTypeOf(mime, name) {
                    if (/^image\//.test(mime)) return 'image';
                    if (/^video\//.test(mime)) return 'video';
                    if (/^audio\//.test(mime)) return 'audio';
                    return 'document';
                },

                startNewChat() {
                    const digits = (this.newChatPhone || '').replace(/\D+/g, '');
                    if (! digits) return;
                    this.newChatOpen = false;
                    this.newChatPhone = '';
                    this.openThread(digits);
                },

                previewOf(c) {
                    if (c.last_type && c.last_type !== 'text') return '📎 ' + c.last_type;
                    return c.last_body || '—';
                },

                tickFor(status) {
                    if (status === 'read') return '✓✓';
                    if (status === 'delivered') return '✓✓';
                    if (status === 'failed') return '⚠';
                    return '✓';
                },

                // ---- Unread bookkeeping (client-side; presentation only) ----
                seenKey(deviceId, phone) { return deviceId + ':' + phone; },

                markSeen() {
                    if (! this.activePhone || ! this.lastMessageId) return;
                    this.seen[this.seenKey(this.activeDeviceId, this.activePhone)] = this.lastMessageId;
                    localStorage.setItem('rio-chat-seen', JSON.stringify(this.seen));
                },

                isUnread(c) {
                    if (c.last_direction !== 'in') return false;
                    if (this.activePhone === c.phone) return false;
                    return c.last_id > (this.seen[this.seenKey(this.activeDeviceId, c.phone)] || 0);
                },

                unreadForDevice(deviceId) {
                    const list = this.convCache[deviceId] || [];
                    return list.filter(c => c.last_direction === 'in'
                        && ! (deviceId === this.activeDeviceId && this.activePhone === c.phone)
                        && c.last_id > (this.seen[this.seenKey(deviceId, c.phone)] || 0)).length;
                },

                mobileThreadOpen: false,

                scrollToEnd() {
                    const el = this.$refs.scroller;
                    if (el) el.scrollTop = el.scrollHeight;
                },
            };
        };
    </script>
    @endpush
    @endif
</x-app-layout>
