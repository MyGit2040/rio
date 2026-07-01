<x-guest-layout>
    <div class="mb-4 text-sm text-gray-600">
        {{ $method === 'email'
            ? 'Enter the 6-digit code we emailed you.'
            : 'Enter the 6-digit code from your authenticator app.' }}
    </div>

    @if (session('error'))
        <div class="mb-4 rounded-lg bg-red-50 border border-red-200 text-red-800 px-4 py-3 text-sm">{{ session('error') }}</div>
    @endif
    @if (session('success'))
        <div class="mb-4 rounded-lg bg-green-50 border border-green-200 text-green-800 px-4 py-3 text-sm">{{ session('success') }}</div>
    @endif

    <form method="POST" action="{{ route('two-factor.store') }}">
        @csrf
        <div>
            <x-input-label for="code" :value="__('Authentication code')" />
            <x-text-input id="code" class="block mt-1 w-full tracking-[0.5em] text-center text-lg" type="text"
                          name="code" inputmode="numeric" autocomplete="one-time-code" autofocus required />
            <x-input-error :messages="$errors->get('code')" class="mt-2" />
        </div>

        <div class="flex items-center justify-between mt-6">
            @if ($method === 'email')
                <button form="resend-form" class="text-sm text-gray-500 hover:text-gray-800 underline">Resend code</button>
            @else
                <span></span>
            @endif
            <x-primary-button>{{ __('Verify') }}</x-primary-button>
        </div>
    </form>

    @if ($method === 'email')
        <form id="resend-form" method="POST" action="{{ route('two-factor.resend') }}">@csrf</form>
    @endif
</x-guest-layout>
