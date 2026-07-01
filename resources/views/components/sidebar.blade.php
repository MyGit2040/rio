@props(['logo' => null, 'brandName' => 'Eagle'])

{{-- Fixed white left navigation. Only links to pages that actually exist (no dead UI). --}}
<aside
    class="fixed inset-y-0 left-0 z-40 w-64 bg-white border-r border-gray-200 transform transition-transform duration-200 lg:translate-x-0 lg:static lg:inset-auto"
    :class="sidebarOpen ? 'translate-x-0' : '-translate-x-full'">

    <div class="flex items-center gap-2.5 h-16 px-5 border-b border-gray-100">
        @if ($logo)
            <img src="{{ \Illuminate\Support\Facades\Storage::url($logo) }}" alt="logo" class="w-9 h-9 rounded-lg object-cover">
        @else
            <span class="grid place-items-center w-9 h-9 rounded-lg bg-brand text-white font-bold">{{ strtoupper(substr($brandName, 0, 1)) }}</span>
        @endif
        <span class="font-semibold text-gray-800 text-lg truncate">{{ $brandName }}</span>
    </div>

    <nav class="px-3 py-4 space-y-1 text-sm overflow-y-auto" style="max-height: calc(100vh - 4rem);">
        <x-nav-item :active="request()->routeIs('dashboard')" href="{{ route('dashboard') }}" icon="grid">Dashboard</x-nav-item>
        <x-nav-item :active="request()->routeIs('devices.*')" href="{{ route('devices.index') }}" icon="device">Devices</x-nav-item>
        <x-nav-item :active="request()->routeIs('inbox.*')" href="{{ route('inbox.index') }}" icon="inbox">Inbox</x-nav-item>
        <x-nav-item :active="request()->routeIs('templates.*')" href="{{ route('templates.index') }}" icon="doc">Templates</x-nav-item>
        <x-nav-item :active="request()->routeIs('media.*')" href="{{ route('media.index') }}" icon="image">Media library</x-nav-item>
        <x-nav-item :active="(request()->routeIs('contacts.*') && ! request()->routeIs('contacts.index')) || (request()->routeIs('contacts.index') && request('status') !== 'opted_out')" href="{{ route('contacts.index') }}" icon="users">Contacts</x-nav-item>
        <x-nav-item :active="request()->routeIs('groups.*')" href="{{ route('groups.index') }}" icon="tag">Groups</x-nav-item>
        <x-nav-item :active="request()->routeIs('campaigns.*')" href="{{ route('campaigns.index') }}" icon="send">Bulk messages</x-nav-item>
        <x-nav-item :active="request()->routeIs('sequences.*')" href="{{ route('sequences.index') }}" icon="drip">Drip sequences</x-nav-item>
        <x-nav-item :active="request()->routeIs('chatbot.*')" href="{{ route('chatbot.index') }}" icon="bot">Auto reply</x-nav-item>
        <x-nav-item :active="request()->routeIs('reports.*')" href="{{ route('reports.index') }}" icon="chart">Reports</x-nav-item>
        <x-nav-item :active="request()->routeIs('health.*')" href="{{ route('health.index') }}" icon="pulse">Number health</x-nav-item>
        <x-nav-item :active="request()->routeIs('spam.*')" href="{{ route('spam.index') }}" icon="shield">Spam checker</x-nav-item>
        <x-nav-item :active="request()->routeIs('invoices.*')" href="{{ route('invoices.index') }}" icon="doc">Orders</x-nav-item>

        <div class="pt-3 mt-3 border-t border-gray-100"></div>
        <p class="px-3 pb-1 text-[11px] font-semibold uppercase tracking-wide text-gray-400">Compliance</p>
        <x-nav-item :active="request()->routeIs('contacts.index') && request('status') === 'opted_out'" href="{{ route('contacts.index', ['status' => 'opted_out']) }}" icon="optout">Opt-out management</x-nav-item>
        <x-nav-item :active="request()->routeIs('suppressions.*')" href="{{ route('suppressions.index') }}" icon="optout">Do-not-contact</x-nav-item>

        <div class="pt-3 mt-3 border-t border-gray-100"></div>
        <x-nav-item :active="request()->routeIs('settings.*') || request()->routeIs('users.*') || request()->routeIs('api-tokens.*') || request()->routeIs('backup.*') || request()->routeIs('security.*') || request()->routeIs('billing.*') || request()->routeIs('webhook-endpoints.*') || request()->routeIs('audit.*')" href="{{ route('settings.edit') }}" icon="cog">Settings</x-nav-item>
    </nav>
</aside>
