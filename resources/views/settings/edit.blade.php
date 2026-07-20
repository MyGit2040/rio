<x-app-layout>
    <x-slot name="header">Settings</x-slot>

    @php
        $s = $tenant->settings ?? [];
        $logoPath = data_get($s, 'logo_path');
        $faviconPath = data_get($s, 'favicon_path');
        $tabs = [
            'branding' => ['Branding', 'tag'],
            'engine'   => ['WhatsApp engine', 'device'],
            'sending'  => ['Sending & safety', 'send'],
            'email'    => ['Email (SMTP)', 'doc'],
            'ai'       => ['AI content', 'bot'],
            'health'   => ['Health & crons', 'shield'],
            'account'  => ['Account & admin', 'cog'],
        ];
    @endphp

    <div x-data="{ tab: (location.hash || '#branding').slice(1) }" class="lg:grid lg:grid-cols-4 lg:gap-6 max-w-5xl">
        {{-- LEFT sub-menu --}}
        <aside class="lg:col-span-1 mb-4 lg:mb-0">
            <nav class="lg:sticky lg:top-20 flex lg:flex-col gap-1 overflow-x-auto bg-white lg:bg-transparent rounded-xl lg:rounded-none border lg:border-0 border-gray-200 p-1 lg:p-0">
                @foreach ($tabs as $key => [$label, $icon])
                    <button type="button" @click="tab = '{{ $key }}'; location.hash = '{{ $key }}'"
                            :class="tab === '{{ $key }}' ? 'sidebar-active font-semibold' : 'text-gray-600 hover:bg-gray-100'"
                            class="flex items-center gap-2.5 px-3 py-2.5 rounded-lg text-sm whitespace-nowrap transition">
                        <x-nav-icon :icon="$icon" class="w-4 h-4 shrink-0" />
                        {{ $label }}
                        @if ($key === 'health' && ($healthOverall ?? 'ok') !== 'ok')
                            <span class="ml-auto w-2 h-2 rounded-full {{ $healthOverall === 'warning' ? 'bg-amber-500' : 'bg-red-500' }}"></span>
                        @endif
                    </button>
                @endforeach

                {{-- Workspace admin — full pages, listed just below "Account & admin" (owner only). --}}
                @if (auth()->user()?->isOwner())
                    <div class="hidden lg:block pt-2 mt-2 border-t border-gray-100"></div>
                    <p class="hidden lg:block px-3 pb-1 text-[11px] font-semibold uppercase tracking-wide text-gray-400">Workspace</p>
                    @foreach ([
                        ['Billing & plans', route('billing.index'), 'chart'],
                        ['Team members', route('users.index'), 'users'],
                        ['REST API tokens', route('api-tokens.index'), 'doc'],
                        ['Outbound webhooks', route('webhook-endpoints.index'), 'send'],
                        ['Audit log', route('audit.index'), 'doc'],
                        ['Backup & restore', route('backup.index'), 'doc'],
                        ['Two-factor (2FA)', route('security.edit'), 'shield'],
                        ['Google contacts', route('settings.google-contacts'), 'users'],
                    ] as [$wLabel, $wHref, $wIcon])
                        <a href="{{ $wHref }}"
                           class="flex items-center gap-2.5 px-3 py-2.5 rounded-lg text-sm whitespace-nowrap transition text-gray-600 hover:bg-gray-100">
                            <x-nav-icon :icon="$wIcon" class="w-4 h-4 shrink-0" />
                            {{ $wLabel }}
                        </a>
                    @endforeach
                @endif
            </nav>
        </aside>

        {{-- RIGHT content --}}
        <div class="lg:col-span-3">
    <form method="POST" action="{{ route('settings.update') }}" enctype="multipart/form-data" class="space-y-6">
        @csrf @method('PUT')

        {{-- Branding --}}
        <x-card title="Branding" subtitle="Your logo, name and accent colour appear across the app." x-show="tab === 'branding'" x-cloak>
            <div class="space-y-4">
                <div class="flex items-center gap-4">
                    <span class="grid place-items-center w-14 h-14 rounded-xl bg-gray-100 overflow-hidden">
                        @if ($logoPath)
                            <img src="{{ \Illuminate\Support\Facades\Storage::url($logoPath) }}" alt="logo" class="w-14 h-14 object-cover">
                        @else
                            <span class="text-gray-400 text-xs">No logo</span>
                        @endif
                    </span>
                    <div class="flex-1">
                        <x-input-label for="logo" value="Logo (PNG, JPG, SVG — max 2MB)" />
                        <input id="logo" name="logo" type="file" accept="image/*"
                               class="mt-1 block w-full text-sm text-gray-600 file:mr-3 file:py-2 file:px-4 file:rounded-lg file:border-0 file:bg-brand file:text-white">
                    </div>
                </div>

                {{-- Favicon — shows in the browser tab / address bar --}}
                <div class="flex items-center gap-4">
                    <span class="grid place-items-center w-14 h-14 rounded-xl bg-gray-100 overflow-hidden">
                        @if ($faviconPath)
                            <img src="{{ \Illuminate\Support\Facades\Storage::url($faviconPath) }}" alt="favicon" class="w-8 h-8 object-contain">
                        @else
                            <span class="text-gray-400 text-[10px] text-center leading-tight">No<br>icon</span>
                        @endif
                    </span>
                    <div class="flex-1">
                        <x-input-label for="favicon" value="Favicon (browser tab icon — PNG, ICO, SVG · square · max 1MB)" />
                        <input id="favicon" name="favicon" type="file" accept="image/png,image/x-icon,image/svg+xml,image/webp,.ico"
                               class="mt-1 block w-full text-sm text-gray-600 file:mr-3 file:py-2 file:px-4 file:rounded-lg file:border-0 file:bg-brand file:text-white">
                        <p class="text-xs text-gray-400 mt-1">Appears in the browser tab and address bar.</p>
                    </div>
                </div>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <x-input-label for="brand_name" value="Display name" />
                        <x-text-input id="brand_name" name="brand_name" class="block mt-1 w-full" :value="old('brand_name', data_get($s, 'brand_name'))" placeholder="{{ config('app.name') }}" />
                    </div>
                    <div>
                        <x-input-label for="accent_color" value="Accent colour" />
                        <input id="accent_color" name="accent_color" type="color" value="{{ old('accent_color', data_get($s, 'accent_color', '#8b5cf6')) }}"
                               class="mt-1 block h-10 w-20 rounded-lg border border-gray-300 cursor-pointer">
                    </div>
                </div>
            </div>
        </x-card>

        {{-- Engine --}}
        <x-card title="WhatsApp engine" subtitle="Where your messages are actually sent from." x-show="tab === 'engine'" x-cloak>
            {{-- Live background-engine pill: scheduler cron + queue worker (native CronHealth heartbeat). --}}
            <x-slot:actions>
                <span class="inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-xs font-medium {{ ($queueActive ?? false) ? 'bg-green-50 text-green-700 border border-green-200' : 'bg-red-50 text-red-700 border border-red-200' }}"
                      title="{{ ($queueActive ?? false) ? 'Scheduler cron + queue worker are firing (heartbeat fresh within 2 min).' : 'No scheduler heartbeat in the last 2 minutes — the per-minute cron may not be running, so queued messages will not send.' }}">
                    <span class="w-1.5 h-1.5 rounded-full {{ ($queueActive ?? false) ? 'bg-green-500' : 'bg-red-500' }}"></span>
                    Queue: {{ ($queueActive ?? false) ? 'Active' : 'Inactive' }}
                </span>
            </x-slot:actions>
            <div class="space-y-4">
                <div class="rounded-lg px-4 py-3 text-sm {{ $engineReady ? 'bg-green-50 text-green-700 border border-green-200' : 'bg-yellow-50 text-yellow-800 border border-yellow-200' }}">
                    {{ $engineReady ? 'Engine is configured.' : 'Engine not configured yet — pick an engine and add its details below.' }}
                </div>

                {{-- Engine selector --}}
                <div>
                    <x-input-label for="whatsapp_driver" value="Sending engine" />
                    <input type="hidden" name="whatsapp_driver" value="openwa">
                    <div class="block mt-1 w-full rounded-lg border border-gray-300 bg-gray-50 px-3 py-2 text-sm text-gray-700">OpenWA Easy API</div>
                    <p class="text-xs text-gray-500 mt-1">
                        The default engine for new numbers. Numbers already linked keep the engine they were created on.
                    </p>
                </div>

                {{-- OpenWA gateway fields --}}
                <div class="space-y-4">
                    <div>
                        <x-input-label for="openwa_base_url" value="Easy API URL" />
                        <x-text-input id="openwa_base_url" name="openwa_base_url" class="block mt-1 w-full" placeholder="http://your-vps-ip:8080" :value="old('openwa_base_url', $tenant->openwa_base_url)" />
                    </div>
                    <div>
                        <x-input-label for="openwa_api_key" value="API key" />
                        <x-text-input id="openwa_api_key" name="openwa_api_key" class="block mt-1 w-full" placeholder="Matches OpenWA --api-key" :value="old('openwa_api_key', $tenant->openwa_api_key)" />
                    </div>
                    <div>
                        <x-input-label for="openwa_session_id" value="OpenWA session ID" />
                        <x-text-input id="openwa_session_id" name="openwa_session_id" class="block mt-1 w-full" placeholder="sales" :value="old('openwa_session_id', $tenant->openwa_session_id)" />
                        <p class="text-xs text-gray-500 mt-1">Default session used for connection checks. Each device you add creates its own OpenWA session automatically.</p>
                    </div>
                </div>

                <div class="pt-2 border-t border-gray-100">
                    <label class="inline-flex items-center gap-2">
                        <input type="hidden" name="ai_enabled" value="0">
                        <input type="checkbox" name="ai_enabled" value="1" @checked($aiEnabled) class="rounded border-gray-300 text-brand focus:ring-brand">
                        <span class="text-sm text-gray-700">Enable AI chatbot replies</span>
                    </label>
                    <p class="text-xs text-gray-500 mt-1">Uses <strong>your own AI key</strong> — set it in the <button type="button" @click="tab = 'ai'; location.hash = 'ai'" class="text-brand underline">AI content</button> section.</p>
                </div>
            </div>
        </x-card>

        {{-- Bulk messaging safety --}}
        <x-card title="Bulk messaging" subtitle="Pacing &amp; safety for campaigns. Defaults can be overridden per campaign." x-show="tab === 'sending'" x-cloak>
            <div class="space-y-5">
                <label class="flex items-start gap-3">
                    <input type="hidden" name="bulk_spintax" value="0">
                    <input type="checkbox" name="bulk_spintax" value="1" @checked(data_get($s, 'bulk_spintax', true)) class="mt-1 rounded border-gray-300 text-brand focus:ring-brand">
                    <span>
                        <span class="text-sm font-medium text-gray-800">Spintax variation</span>
                        <span class="block text-xs text-gray-500">Rotate wording so messages read naturally, e.g. <code>{Hi|Hello|Good morning}</code>.</span>
                    </span>
                </label>

                <div>
                    <x-input-label value="Default delivery delay (seconds)" />
                    <div class="grid grid-cols-2 gap-4 mt-1">
                        <div>
                            <x-text-input name="bulk_delay_min" type="number" min="1" max="600" class="block w-full" :value="old('bulk_delay_min', data_get($s, 'bulk_delay_min', 40))" />
                            <p class="text-xs text-gray-400 mt-1">Min</p>
                        </div>
                        <div>
                            <x-text-input name="bulk_delay_max" type="number" min="1" max="600" class="block w-full" :value="old('bulk_delay_max', data_get($s, 'bulk_delay_max', 90))" />
                            <p class="text-xs text-gray-400 mt-1">Max</p>
                        </div>
                    </div>
                    <p class="text-xs text-gray-500 mt-1">A pause between each message keeps a steady, predictable send rate.</p>
                </div>

                <div>
                    <x-input-label value="Sleep timing" />
                    <div class="grid grid-cols-2 gap-4 mt-1">
                        <div>
                            <x-text-input name="bulk_sleep_after" type="number" min="0" max="1000" class="block w-full" :value="old('bulk_sleep_after', data_get($s, 'bulk_sleep_after', 0))" />
                            <p class="text-xs text-gray-400 mt-1">Pause after every N messages (0 = off)</p>
                        </div>
                        <div>
                            <x-text-input name="bulk_sleep_seconds" type="number" min="0" max="3600" class="block w-full" :value="old('bulk_sleep_seconds', data_get($s, 'bulk_sleep_seconds', 0))" />
                            <p class="text-xs text-gray-400 mt-1">Sleep duration (seconds)</p>
                        </div>
                    </div>
                </div>

                <div class="rounded-lg border border-gray-200 bg-gray-50 px-4 py-3 text-sm">
                    <p class="font-medium text-gray-800">Verified WhatsApp recipients only</p>
                    <p class="mt-1 text-xs text-gray-500">Campaigns and sequences use contacts confirmed by the <strong>Verify WhatsApp</strong> button in Contacts. This prevents delivery attempts to numbers that are not on WhatsApp.</p>
                </div>

                <label class="flex items-start gap-3">
                    <input type="hidden" name="bulk_device_failover" value="0">
                    <input type="checkbox" name="bulk_device_failover" value="1" @checked(data_get($s, 'bulk_device_failover', true)) class="mt-1 rounded border-gray-300 text-brand focus:ring-brand">
                    <span>
                        <span class="text-sm font-medium text-gray-800">Auto device failover</span>
                        <span class="block text-xs text-gray-500">Each contact normally stays on the same WhatsApp number (sticky). If that number is disconnected mid-campaign, automatically send from another <strong>connected</strong> number in the campaign instead — so no batch is missed. Off = wait for the original number to reconnect.</span>
                    </span>
                </label>

                <div>
                    <x-input-label for="bulk_hook_number" value="Hook number — forward replies to" />
                    <x-text-input id="bulk_hook_number" name="bulk_hook_number" class="block mt-1 w-full" placeholder="971501234567" :value="old('bulk_hook_number', data_get($s, 'bulk_hook_number'))" />
                    <p class="text-xs text-gray-500 mt-1">When a contact replies, a copy is forwarded to this WhatsApp number. Full number with country code.</p>
                </div>

                <div>
                    <x-input-label for="bulk_random_prefix" value="Random number prefix" />
                    <x-text-input id="bulk_random_prefix" name="bulk_random_prefix" class="block mt-1 w-full" placeholder="EG-" :value="old('bulk_random_prefix', data_get($s, 'bulk_random_prefix'))" />
                    <p class="text-xs text-gray-500 mt-1">Used before <code>@{{random}}</code>, <code>[random]</code>, and <code>@{{variant_ref_id}}</code>. Example: EG-482913.</p>
                </div>
            </div>
        </x-card>

        {{-- Quiet hours --}}
        <x-card title="Quiet hours" subtitle="Hold campaign sends overnight — messages queued during this window go out when it ends." x-show="tab === 'sending'" x-cloak>
            <div class="space-y-4">
                <label class="flex items-start gap-3">
                    <input type="hidden" name="quiet_hours_enabled" value="0">
                    <input type="checkbox" name="quiet_hours_enabled" value="1" @checked(data_get($s, 'quiet_hours_enabled', false)) class="mt-1 rounded border-gray-300 text-brand focus:ring-brand">
                    <span>
                        <span class="text-sm font-medium text-gray-800">Don't send during quiet hours</span>
                        <span class="block text-xs text-gray-500">Messages due in this window are delayed until it ends. Times are measured in the timezone selected below.</span>
                    </span>
                </label>
                @php $quietTz = old('quiet_timezone', data_get($s, 'quiet_timezone', config('app.timezone', 'UTC'))); @endphp
                <div class="grid grid-cols-2 gap-4 max-w-sm">
                    <div>
                        <x-input-label for="quiet_start" value="From" />
                        <input id="quiet_start" name="quiet_start" type="time" value="{{ old('quiet_start', data_get($s, 'quiet_start', '21:00')) }}"
                               class="mt-1 block w-full rounded-lg border-gray-300 text-sm focus:ring-brand focus:border-brand">
                    </div>
                    <div>
                        <x-input-label for="quiet_end" value="Until" />
                        <input id="quiet_end" name="quiet_end" type="time" value="{{ old('quiet_end', data_get($s, 'quiet_end', '08:00')) }}"
                               class="mt-1 block w-full rounded-lg border-gray-300 text-sm focus:ring-brand focus:border-brand">
                    </div>
                </div>
                <div class="max-w-sm">
                    <x-input-label for="quiet_timezone" value="Timezone" />
                    <select id="quiet_timezone" name="quiet_timezone"
                            class="mt-1 block w-full rounded-lg border-gray-300 text-sm focus:ring-brand focus:border-brand">
                        @foreach (DateTimeZone::listIdentifiers() as $tz)
                            <option value="{{ $tz }}" @selected($quietTz === $tz)>{{ $tz }}</option>
                        @endforeach
                    </select>
                    <span class="block mt-1 text-xs text-gray-500">Quiet hours are read in this timezone. Set it to your local time (e.g. Asia/Dubai) so sends pause at the right hour.</span>
                </div>
            </div>
        </x-card>

        {{-- Opt-out handling --}}
        <x-card title="Opt-out keywords" subtitle="When a contact replies one of these words, they're unsubscribed and added to the do-not-contact list automatically." x-show="tab === 'sending'" x-cloak>
            <div class="space-y-4">
                <div>
                    <x-input-label for="optout_keywords" value="Keywords (comma separated)" />
                    <x-text-input id="optout_keywords" name="optout_keywords" class="block mt-1 w-full" placeholder="STOP, UNSUBSCRIBE, CANCEL"
                                  :value="old('optout_keywords', data_get($s, 'optout_keywords', 'STOP,UNSUBSCRIBE,CANCEL,END,QUIT'))" />
                </div>
                <div>
                    <x-input-label for="optout_reply" value="Confirmation reply (optional)" />
                    <textarea id="optout_reply" name="optout_reply" rows="2" placeholder="You've been unsubscribed and won't receive further messages."
                              class="mt-1 block w-full rounded-lg border-gray-300 text-sm focus:ring-brand focus:border-brand">{{ old('optout_reply', data_get($s, 'optout_reply')) }}</textarea>
                    <p class="text-xs text-gray-500 mt-1">Sent back automatically when a contact opts out. Leave blank for the default.</p>
                </div>
            </div>
        </x-card>

        {{-- SMTP --}}
        <x-card title="Email (SMTP)" subtitle="Used for login codes and notifications. Leave blank to use the platform default." x-show="tab === 'email'" x-cloak>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <x-input-label for="smtp_host" value="Host" />
                    <x-text-input id="smtp_host" name="smtp_host" class="block mt-1 w-full" placeholder="smtp.example.com" :value="old('smtp_host', data_get($s, 'smtp_host'))" />
                </div>
                <div>
                    <x-input-label for="smtp_port" value="Port" />
                    <x-text-input id="smtp_port" name="smtp_port" type="number" class="block mt-1 w-full" placeholder="587" :value="old('smtp_port', data_get($s, 'smtp_port'))" />
                </div>
                <div>
                    <x-input-label for="smtp_user" value="Username" />
                    <x-text-input id="smtp_user" name="smtp_user" class="block mt-1 w-full" :value="old('smtp_user', data_get($s, 'smtp_user'))" />
                </div>
                <div>
                    <x-input-label for="smtp_pass" value="Password" />
                    <x-password-input id="smtp_pass" name="smtp_pass" placeholder="{{ data_get($s, 'smtp_pass') ? '••••••• (unchanged)' : '' }}" autocomplete="new-password" />
                </div>
                <div>
                    <x-input-label for="smtp_from" value="From address" />
                    <x-text-input id="smtp_from" name="smtp_from" type="email" class="block mt-1 w-full" :value="old('smtp_from', data_get($s, 'smtp_from'))" />
                </div>
                <div>
                    <x-input-label for="smtp_encryption" value="Encryption" />
                    <select id="smtp_encryption" name="smtp_encryption" class="mt-1 block w-full rounded-lg border-gray-300 text-sm focus:ring-brand focus:border-brand">
                        @foreach (['tls' => 'TLS', 'ssl' => 'SSL', 'none' => 'None'] as $v => $l)
                            <option value="{{ $v }}" @selected(data_get($s, 'smtp_encryption', 'tls') === $v)>{{ $l }}</option>
                        @endforeach
                    </select>
                </div>
            </div>
            <div class="mt-4 pt-4 border-t border-gray-100 flex items-center gap-3 flex-wrap">
                <button type="button" onclick="eagleTest('{{ route('settings.test-email') }}', this, 'smtp-test-result')"
                        class="inline-flex items-center gap-2 px-3 py-2 rounded-lg bg-white border border-gray-300 text-sm text-gray-700 hover:bg-gray-50">Send test email</button>
                <span id="smtp-test-result" class="text-sm"></span>
                <span class="text-xs text-gray-400 w-full sm:w-auto">Save your settings first — the test uses the saved SMTP details and emails {{ auth()->user()->email }}.</span>
            </div>
        </x-card>

        {{-- AI content generation --}}
        <x-card title="AI content generation" subtitle="Your own key for ChatGPT, Gemini or Claude — powers the ✨ Generate variants button in templates." x-show="tab === 'ai'" x-cloak>
            <div class="space-y-4">
                <div>
                    <x-input-label for="ai_provider" value="Which AI to use" />
                    <select id="ai_provider" name="ai_provider" class="mt-1 block w-full rounded-lg border-gray-300 text-sm focus:ring-brand focus:border-brand">
                        @foreach (['openai' => 'ChatGPT (OpenAI)', 'gemini' => 'Google Gemini', 'claude' => 'Anthropic Claude'] as $v => $l)
                            <option value="{{ $v }}" @selected(data_get($s, 'ai_provider', 'openai') === $v)>{{ $l }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                    <div>
                        <x-input-label for="ai_openai_key" value="ChatGPT key" />
                        <x-password-input id="ai_openai_key" name="ai_openai_key" autocomplete="off" placeholder="{{ data_get($s, 'ai_openai_key') ? '•••• saved' : 'sk-…' }}" />
                    </div>
                    <div>
                        <x-input-label for="ai_gemini_key" value="Gemini key" />
                        <x-password-input id="ai_gemini_key" name="ai_gemini_key" autocomplete="off" placeholder="{{ data_get($s, 'ai_gemini_key') ? '•••• saved' : 'AIza…' }}" />
                    </div>
                    <div>
                        <x-input-label for="ai_claude_key" value="Claude key" />
                        <x-password-input id="ai_claude_key" name="ai_claude_key" autocomplete="off" placeholder="{{ data_get($s, 'ai_claude_key') ? '•••• saved' : 'sk-ant-…' }}" />
                    </div>
                </div>
                <p class="text-xs text-gray-500">Only the selected provider's key is used. Leave a field blank to keep its saved key.</p>
                <div class="pt-3 border-t border-gray-100 flex items-center gap-3 flex-wrap">
                    <button type="button" onclick="eagleTest('{{ route('settings.test-ai') }}', this, 'ai-test-result')"
                            class="inline-flex items-center gap-2 px-3 py-2 rounded-lg bg-white border border-gray-300 text-sm text-gray-700 hover:bg-gray-50">Test connection</button>
                    <span id="ai-test-result" class="text-sm"></span>
                    <span class="text-xs text-gray-400 w-full sm:w-auto">Save your key first, then test.</span>
                </div>
            </div>
        </x-card>

        <x-btn type="submit" variant="primary" x-show="tab !== 'account'" x-cloak>Save settings</x-btn>
    </form>

    @push('scripts')
    <script>
        function eagleTest(url, btn, resultId) {
            const result = document.getElementById(resultId);
            const original = btn.textContent;
            btn.disabled = true; btn.textContent = 'Testing…';
            result.textContent = ''; result.className = 'text-sm';

            fetch(url, { method: 'POST', headers: {
                'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content,
                'Accept': 'application/json',
            }})
                .then(r => r.json())
                .then(d => {
                    result.textContent = d.message || (d.ok ? 'OK' : 'Failed.');
                    result.className = 'text-sm ' + (d.ok ? 'text-green-600' : 'text-red-600');
                })
                .catch(() => { result.textContent = 'Could not reach the server.'; result.className = 'text-sm text-red-600'; })
                .finally(() => { btn.disabled = false; btn.textContent = original; });
        }
    </script>
    @endpush

        {{-- Health & crons --}}
        <div x-show="tab === 'health'" x-cloak class="space-y-6">
            @php
                $statusMeta = [
                    'ok'       => ['bg-green-50 border-green-200 text-green-800', 'bg-green-500', 'Healthy'],
                    'warning'  => ['bg-amber-50 border-amber-200 text-amber-800', 'bg-amber-500', 'Warning'],
                    'error'    => ['bg-red-50 border-red-200 text-red-800', 'bg-red-500', 'Error'],
                    'critical' => ['bg-red-50 border-red-300 text-red-900', 'bg-red-600', 'Critical'],
                ];
                $overall = $healthOverall ?? 'ok';
                [$oBox, , $oLabel] = $statusMeta[$overall] ?? $statusMeta['ok'];
            @endphp

            {{-- Overall banner --}}
            <div class="rounded-xl border px-4 py-3 flex items-center gap-3 {{ $oBox }}">
                <span class="w-2.5 h-2.5 rounded-full {{ $statusMeta[$overall][1] ?? 'bg-green-500' }}"></span>
                <div class="text-sm">
                    <span class="font-semibold">Background engine: {{ $oLabel }}</span>
                    <span class="block text-xs opacity-80">
                        @if ($overall === 'ok')
                            Crons and the queue worker are running. Campaigns will send on schedule.
                        @else
                            Something needs attention below — campaigns may not send until it's resolved.
                        @endif
                    </span>
                </div>
                <a href="{{ route('settings.edit') }}#health" class="ml-auto text-xs underline opacity-80 hover:opacity-100">Refresh</a>
            </div>

            {{-- Individual checks --}}
            <x-card title="System checks" subtitle="Live status of the services that deliver your campaigns.">
                <ul class="divide-y divide-gray-100">
                    @foreach ($healthChecks as $c)
                        @php [$box, $dot, $badge] = $statusMeta[$c['status']] ?? $statusMeta['ok']; @endphp
                        <li class="py-3 flex items-start gap-3">
                            <span class="mt-1 w-2.5 h-2.5 rounded-full shrink-0 {{ $dot }}"></span>
                            <div class="min-w-0 flex-1">
                                <div class="flex items-center gap-2 flex-wrap">
                                    <span class="text-sm font-medium text-gray-800">{{ $c['label'] }}</span>
                                    <span class="text-[11px] font-semibold uppercase tracking-wide px-1.5 py-0.5 rounded {{ $box }}">{{ $badge }}</span>
                                    <span class="text-sm text-gray-600">{{ $c['message'] }}</span>
                                </div>
                                <p class="text-xs text-gray-500 mt-0.5">{{ $c['detail'] }}</p>
                            </div>
                        </li>
                    @endforeach
                </ul>
            </x-card>

            {{-- Process controls (owner/super-admin) — terminal-free worker + failed-job controls --}}
            @if (auth()->user()?->isOwner() || auth()->user()?->isSuperAdmin())
            <x-card title="Process controls" subtitle="Manage the background workers without touching the server terminal.">
                <div class="flex flex-wrap items-center gap-2">
                    <button type="button" onclick="eagleTest('{{ route('settings.restart-workers') }}', this, 'proc-result')"
                            class="inline-flex items-center gap-1.5 px-3 py-2 rounded-lg bg-white border border-gray-300 text-sm text-gray-700 hover:bg-gray-50">
                        <x-nav-icon icon="device" class="w-4 h-4" /> Restart workers
                    </button>
                    <button type="button" onclick="eagleTest('{{ route('settings.retry-failed-jobs') }}', this, 'proc-result')"
                            class="inline-flex items-center gap-1.5 px-3 py-2 rounded-lg bg-white border border-gray-300 text-sm text-gray-700 hover:bg-gray-50">
                        <x-nav-icon icon="send" class="w-4 h-4" /> Retry failed jobs
                    </button>
                    <button type="button"
                            onclick="if (confirm('Clear the failed-jobs log? This cannot be undone.')) eagleTest('{{ route('settings.flush-failed-jobs') }}', this, 'proc-result')"
                            class="inline-flex items-center gap-1.5 px-3 py-2 rounded-lg bg-white border border-red-200 text-sm text-red-600 hover:bg-red-50">
                        Clear failed jobs
                    </button>
                    <span id="proc-result" class="text-sm"></span>
                </div>
                <p class="text-xs text-gray-500 mt-2">
                    <strong>Restart workers</strong> tells the running worker to finish its current message and relaunch (use after a deploy).
                    You never need to run <code class="px-1 bg-gray-100 rounded">queue:work</code> by hand — the cron below drives it.
                </p>
            </x-card>
            @endif

            {{-- Paste-ready cron --}}
            <x-card title="Server cron (paste this once)" subtitle="This single per-minute cron drives everything: scheduled campaigns, drip sequences and the queue worker.">
                <div class="space-y-3" x-data="{ copied: false }">
                    <div class="relative">
                        <pre id="cron-line" class="bg-gray-900 text-gray-100 text-xs rounded-lg p-3 pr-24 overflow-x-auto whitespace-pre">{{ $cronLine }}</pre>
                        <button type="button"
                                @click="navigator.clipboard.writeText(document.getElementById('cron-line').textContent.trim()).then(() => { copied = true; setTimeout(() => copied = false, 1500); })"
                                class="absolute top-2 right-2 px-2.5 py-1 rounded-md bg-white/10 hover:bg-white/20 text-white text-xs">
                            <span x-show="!copied">Copy</span><span x-show="copied" x-cloak>Copied ✓</span>
                        </button>
                    </div>
                    <p class="text-xs text-gray-500">
                        Add it with <code class="px-1 bg-gray-100 rounded">crontab -e</code> on the server.
                        If <code class="px-1 bg-gray-100 rounded">php</code> isn't on the cron PATH, use the full binary path (e.g. <code class="px-1 bg-gray-100 rounded">/usr/local/bin/php</code>).
                        @if ($cronLastRun)
                            Scheduler last ran <span class="font-medium text-gray-700">{{ $cronLastRun->diffForHumans() }}</span>.
                        @else
                            <span class="text-red-600 font-medium">The scheduler has never run — add this cron now.</span>
                        @endif
                    </p>
                </div>
            </x-card>

            {{-- What runs every minute --}}
            <x-card title="Scheduled tasks" subtitle="What the cron runs on every tick.">
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="text-gray-500 text-left">
                            <tr>
                                <th class="py-2 font-medium">Task</th>
                                <th class="py-2 font-medium">Purpose</th>
                                <th class="py-2 font-medium">Frequency</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            @foreach ($healthTasks as $t)
                                <tr>
                                    <td class="py-2 font-mono text-xs text-gray-800 whitespace-nowrap">{{ $t['command'] }}</td>
                                    <td class="py-2 text-gray-600">{{ $t['purpose'] }}</td>
                                    <td class="py-2 text-gray-500 whitespace-nowrap">Every minute</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </x-card>
        </div>

        {{-- Account & admin --}}
        <div x-show="tab === 'account'" x-cloak class="space-y-6">
            <x-card title="Workspace">
                <dl class="text-sm space-y-2">
                    <div class="flex justify-between"><dt class="text-gray-500">Name</dt><dd class="text-gray-800">{{ $tenant->name }}</dd></div>
                    <div class="flex justify-between"><dt class="text-gray-500">Plan</dt><dd class="text-gray-800">{{ ucfirst($tenant->plan) }}</dd></div>
                    <div class="flex justify-between"><dt class="text-gray-500">Your account</dt><dd><a href="{{ route('profile.edit') }}" class="text-brand">Edit profile →</a></dd></div>
                    <div class="flex justify-between"><dt class="text-gray-500">Login security</dt><dd><a href="{{ route('security.edit') }}" class="text-brand">Two-factor (2FA) →</a></dd></div>
                </dl>
            </x-card>

            @if (auth()->user()->isSuperAdmin())
                <x-card title="Platform admin" subtitle="Manage client workspaces, plans and module access.">
                    <a href="{{ route('admin.workspaces.index') }}" class="flex items-center gap-3 p-3 rounded-lg border border-brand/30 bg-brand/5 hover:bg-brand/10">
                        <span class="font-medium text-brand">Workspaces &amp; module access →</span>
                    </a>
                </x-card>
            @endif

            {{-- "Workspace admin" links live in the sidebar's Workspace sub-menu now. --}}
        </div>
        </div>{{-- /right content --}}
    </div>{{-- /settings layout --}}
</x-app-layout>
