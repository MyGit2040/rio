<x-app-layout>
    <x-slot name="header">Settings</x-slot>

    @php
        $s = $tenant->settings ?? [];
        $logoPath = data_get($s, 'logo_path');
        $tabs = [
            'branding' => ['Branding', 'tag'],
            'engine'   => ['WhatsApp engine', 'device'],
            'sending'  => ['Sending & safety', 'send'],
            'email'    => ['Email (SMTP)', 'doc'],
            'ai'       => ['AI content', 'bot'],
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
                    </button>
                @endforeach
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
        <x-card title="WhatsApp engine (Evolution API)" subtitle="Where your messages are actually sent from." x-show="tab === 'engine'" x-cloak>
            <div class="space-y-4">
                <div class="rounded-lg px-4 py-3 text-sm {{ $engineReady ? 'bg-green-50 text-green-700 border border-green-200' : 'bg-yellow-50 text-yellow-800 border border-yellow-200' }}">
                    {{ $engineReady ? 'Engine is configured.' : 'Engine not configured yet — add the URL and key below.' }}
                </div>
                <div>
                    <x-input-label for="evolution_base_url" value="Engine URL" />
                    <x-text-input id="evolution_base_url" name="evolution_base_url" class="block mt-1 w-full" placeholder="http://your-vps-ip:8080" :value="old('evolution_base_url', $tenant->evolution_base_url)" />
                    @if ($platformUrl)<p class="text-xs text-gray-500 mt-1">Leave blank to use the platform default ({{ $platformUrl }}).</p>@endif
                </div>
                <div>
                    <x-input-label for="evolution_api_key" value="API key" />
                    <x-text-input id="evolution_api_key" name="evolution_api_key" class="block mt-1 w-full" placeholder="Your Evolution AUTHENTICATION_API_KEY" :value="old('evolution_api_key', $tenant->evolution_api_key)" />
                </div>
                <div class="pt-2 border-t border-gray-100">
                    <label class="inline-flex items-center gap-2">
                        <input type="hidden" name="ai_enabled" value="0">
                        <input type="checkbox" name="ai_enabled" value="1" @checked($aiEnabled) class="rounded border-gray-300 text-brand focus:ring-brand">
                        <span class="text-sm text-gray-700">Enable AI chatbot replies</span>
                    </label>
                    <p class="text-xs text-gray-500 mt-1">Requires <code>OPENAI_API_KEY</code> in the server's <code>.env</code>.</p>
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

                <label class="flex items-start gap-3">
                    <input type="hidden" name="allow_non_verified" value="0">
                    <input type="checkbox" name="allow_non_verified" value="1" @checked(data_get($s, 'allow_non_verified', true)) class="mt-1 rounded border-gray-300 text-brand focus:ring-brand">
                    <span>
                        <span class="text-sm font-medium text-gray-800">Allow non-verified contacts</span>
                        <span class="block text-xs text-gray-500">Include contacts not yet confirmed on WhatsApp. Off = only send to verified numbers (use <strong>Verify WhatsApp</strong> in Contacts first). Sending to unverified numbers risks failures.</span>
                    </span>
                </label>

                <div>
                    <x-input-label for="bulk_hook_number" value="Hook number — forward replies to" />
                    <x-text-input id="bulk_hook_number" name="bulk_hook_number" class="block mt-1 w-full" placeholder="971501234567" :value="old('bulk_hook_number', data_get($s, 'bulk_hook_number'))" />
                    <p class="text-xs text-gray-500 mt-1">When a contact replies, a copy is forwarded to this WhatsApp number. Full number with country code.</p>
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
                        <span class="block text-xs text-gray-500">Messages due in this window are delayed until it ends. Times are in {{ config('app.timezone', 'UTC') }}.</span>
                    </span>
                </label>
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
                    <x-text-input id="smtp_pass" name="smtp_pass" type="password" class="block mt-1 w-full" placeholder="{{ data_get($s, 'smtp_pass') ? '••••••• (unchanged)' : '' }}" autocomplete="new-password" />
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
                        <x-text-input id="ai_openai_key" name="ai_openai_key" type="password" class="block mt-1 w-full" autocomplete="off" placeholder="{{ data_get($s, 'ai_openai_key') ? '•••• saved' : 'sk-…' }}" />
                    </div>
                    <div>
                        <x-input-label for="ai_gemini_key" value="Gemini key" />
                        <x-text-input id="ai_gemini_key" name="ai_gemini_key" type="password" class="block mt-1 w-full" autocomplete="off" placeholder="{{ data_get($s, 'ai_gemini_key') ? '•••• saved' : 'AIza…' }}" />
                    </div>
                    <div>
                        <x-input-label for="ai_claude_key" value="Claude key" />
                        <x-text-input id="ai_claude_key" name="ai_claude_key" type="password" class="block mt-1 w-full" autocomplete="off" placeholder="{{ data_get($s, 'ai_claude_key') ? '•••• saved' : 'sk-ant-…' }}" />
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

            @if (auth()->user()->isOwner())
                <x-card title="Workspace admin" subtitle="Team, API and backups.">
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                        @foreach ([
                            ['Billing & plans', route('billing.index')],
                            ['Team members', route('users.index')],
                            ['REST API tokens', route('api-tokens.index')],
                            ['Outbound webhooks', route('webhook-endpoints.index')],
                            ['Audit log', route('audit.index')],
                            ['Backup & restore', route('backup.index')],
                            ['Two-factor (2FA)', route('security.edit')],
                        ] as [$label, $href])
                            <a href="{{ $href }}" class="flex items-center gap-3 p-3 rounded-lg border border-gray-200 hover:bg-gray-50">
                                <span class="font-medium text-gray-800">{{ $label }}</span>
                                <span class="ml-auto text-brand">→</span>
                            </a>
                        @endforeach
                    </div>
                </x-card>
            @endif
        </div>
        </div>{{-- /right content --}}
    </div>{{-- /settings layout --}}
</x-app-layout>
