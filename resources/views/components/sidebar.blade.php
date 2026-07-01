@props(['logo' => null, 'brandName' => 'Eagle'])

{{-- Fixed white left navigation. Only links to pages that actually exist (no dead UI). --}}
<aside
    class="fixed inset-y-0 left-0 z-40 w-64 bg-white border-r border-gray-200 transform transition-transform duration-200 lg:translate-x-0 lg:static lg:inset-auto"
    :class="sidebarOpen ? 'translate-x-0' : '-translate-x-full'">

    <div class="flex items-center gap-2.5 h-16 px-4 border-b border-gray-100">
        @if ($logo)
            <img src="{{ \Illuminate\Support\Facades\Storage::url($logo) }}" alt="{{ $brandName }}"
                 class="h-10 w-auto max-w-[150px] object-contain">
        @else
            <span class="grid place-items-center w-10 h-10 rounded-lg bg-brand text-white font-bold text-lg">{{ strtoupper(substr($brandName, 0, 1)) }}</span>
            <span class="font-semibold text-gray-800 text-lg truncate">{{ $brandName }}</span>
        @endif
    </div>

    @php($tenant = auth()->user()?->tenant)
    @php($can = fn ($m) => ! $tenant || $tenant->allows($m))

    <nav class="px-3 py-4 space-y-1 text-sm overflow-y-auto" style="max-height: calc(100vh - 4rem);">
        @if (auth()->user()?->isSuperAdmin())
            <x-nav-item :active="request()->routeIs('admin.*')" href="{{ route('admin.dashboard') }}" icon="cog">Admin panel</x-nav-item>
            <div class="pt-2 mt-2 border-t border-gray-100"></div>
        @endif

        <x-nav-item :active="request()->routeIs('dashboard')" href="{{ route('dashboard') }}" icon="grid">Dashboard</x-nav-item>
        @if ($can('devices'))<x-nav-item :active="request()->routeIs('devices.*')" href="{{ route('devices.index') }}" icon="device">Devices</x-nav-item>@endif
        @if ($can('inbox'))<x-nav-item :active="request()->routeIs('inbox.*')" href="{{ route('inbox.index') }}" icon="inbox">Inbox</x-nav-item>@endif
        @if ($can('templates'))<x-nav-item :active="request()->routeIs('templates.*')" href="{{ route('templates.index') }}" icon="doc">Templates</x-nav-item>@endif
        @if ($can('media'))<x-nav-item :active="request()->routeIs('media.*')" href="{{ route('media.index') }}" icon="image">Media library</x-nav-item>@endif
        @if ($can('contacts'))<x-nav-item :active="(request()->routeIs('contacts.*') && ! request()->routeIs('contacts.index')) || (request()->routeIs('contacts.index') && request('status') !== 'opted_out')" href="{{ route('contacts.index') }}" icon="users">Contacts</x-nav-item>@endif
        @if ($can('groups'))<x-nav-item :active="request()->routeIs('groups.*')" href="{{ route('groups.index') }}" icon="tag">Groups</x-nav-item>@endif
        @if ($can('campaigns'))<x-nav-item :active="request()->routeIs('single-message.*')" href="{{ route('single-message.create') }}" icon="send">Single message</x-nav-item>@endif
        @if ($can('campaigns'))<x-nav-item :active="request()->routeIs('campaigns.*')" href="{{ route('campaigns.index') }}" icon="send">Bulk messages</x-nav-item>@endif
        @if ($can('sequences'))<x-nav-item :active="request()->routeIs('sequences.*')" href="{{ route('sequences.index') }}" icon="drip">Drip sequences</x-nav-item>@endif
        @if ($can('chatbot'))<x-nav-item :active="request()->routeIs('chatbot.*')" href="{{ route('chatbot.index') }}" icon="bot">Auto reply</x-nav-item>@endif
        @if ($can('reports'))<x-nav-item :active="request()->routeIs('reports.*')" href="{{ route('reports.index') }}" icon="chart">Reports</x-nav-item>@endif
        @if ($can('health'))<x-nav-item :active="request()->routeIs('health.*')" href="{{ route('health.index') }}" icon="pulse">Number health</x-nav-item>@endif
        @if ($can('spam'))<x-nav-item :active="request()->routeIs('spam.*')" href="{{ route('spam.index') }}" icon="shield">Spam checker</x-nav-item>@endif
        @if ($can('orders'))<x-nav-item :active="request()->routeIs('invoices.*')" href="{{ route('invoices.index') }}" icon="doc">Orders</x-nav-item>@endif

        <div class="pt-3 mt-3 border-t border-gray-100"></div>
        <p class="px-3 pb-1 text-[11px] font-semibold uppercase tracking-wide text-gray-400">Compliance</p>
        @if ($can('contacts'))<x-nav-item :active="request()->routeIs('contacts.index') && request('status') === 'opted_out'" href="{{ route('contacts.index', ['status' => 'opted_out']) }}" icon="optout">Opt-out management</x-nav-item>@endif
        @if ($can('suppression'))<x-nav-item :active="request()->routeIs('suppressions.*')" href="{{ route('suppressions.index') }}" icon="optout">Do-not-contact</x-nav-item>@endif

        @if (auth()->user()?->isOwner())
            @php($workspaceActive = request()->routeIs('billing.*', 'users.*', 'api-tokens.*', 'webhook-endpoints.*', 'audit.*', 'backup.*', 'security.*'))
            <div class="pt-3 mt-3 border-t border-gray-100"></div>
            <div x-data="{ open: {{ $workspaceActive ? 'true' : 'false' }} }">
                {{-- Parent: click to expand/collapse the workspace sub-menu --}}
                <button type="button" @click="open = ! open"
                    @class([
                        'w-full flex items-center gap-3 px-3 py-2.5 rounded-lg transition',
                        'sidebar-active font-semibold' => $workspaceActive,
                        'text-gray-600 hover:bg-gray-100' => ! $workspaceActive,
                    ])>
                    <svg class="w-5 h-5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 7a2 2 0 012-2h4l2 2h8a2 2 0 012 2v8a2 2 0 01-2 2H5a2 2 0 01-2-2V7z"/></svg>
                    <span class="truncate flex-1 text-left">Workspace</span>
                    <svg class="w-4 h-4 shrink-0 transition-transform" :class="open && 'rotate-90'" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
                </button>
                {{-- Sub-menu: indented children, hidden until the parent is expanded --}}
                <div x-show="open" x-cloak class="mt-1 ml-4 pl-3 border-l border-gray-100 space-y-0.5">
                    <x-nav-item :active="request()->routeIs('billing.*')" href="{{ route('billing.index') }}" icon="chart">Billing &amp; plans</x-nav-item>
                    <x-nav-item :active="request()->routeIs('users.*')" href="{{ route('users.index') }}" icon="users">Team members</x-nav-item>
                    <x-nav-item :active="request()->routeIs('api-tokens.*')" href="{{ route('api-tokens.index') }}" icon="doc">REST API tokens</x-nav-item>
                    <x-nav-item :active="request()->routeIs('webhook-endpoints.*')" href="{{ route('webhook-endpoints.index') }}" icon="send">Outbound webhooks</x-nav-item>
                    <x-nav-item :active="request()->routeIs('audit.*')" href="{{ route('audit.index') }}" icon="doc">Audit log</x-nav-item>
                    <x-nav-item :active="request()->routeIs('backup.*')" href="{{ route('backup.index') }}" icon="doc">Backup &amp; restore</x-nav-item>
                    <x-nav-item :active="request()->routeIs('security.*')" href="{{ route('security.edit') }}" icon="shield">Two-factor (2FA)</x-nav-item>
                </div>
            </div>
        @endif

        <div class="pt-3 mt-3 border-t border-gray-100"></div>
        <x-nav-item :active="request()->routeIs('help.*')" href="{{ route('help.index') }}" icon="doc">Help center</x-nav-item>
        <x-nav-item :active="request()->routeIs('settings.*')" href="{{ route('settings.edit') }}" icon="cog">Settings</x-nav-item>
    </nav>
</aside>
