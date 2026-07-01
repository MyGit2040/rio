<x-app-layout>
    <x-slot name="header">Security</x-slot>

    <div class="max-w-2xl space-y-6">
        <x-card title="Two-factor authentication" subtitle="Add a second step at login for extra protection.">
            @if ($user->two_factor_enabled)
                <div class="flex items-center gap-3 flex-wrap">
                    <x-badge color="green">Enabled</x-badge>
                    <span class="text-sm text-gray-600">Method: {{ $user->two_factor_type === 'email' ? 'Email code' : 'Authenticator app' }}</span>
                    <form method="POST" action="{{ route('security.disable') }}" class="ml-auto" onsubmit="return confirm('Turn off two-factor?')">
                        @csrf
                        <x-btn type="submit" variant="danger">Turn off</x-btn>
                    </form>
                </div>
            @else
                <div class="space-y-6">
                    {{-- Authenticator app --}}
                    <div class="rounded-lg border border-gray-200 p-4">
                        <p class="font-medium text-gray-800">Authenticator app (Google Authenticator, Authy…)</p>
                        @if (session('totp_secret'))
                            @php $uri = \App\Support\Totp::uri(session('totp_secret'), $user->email); @endphp
                            <p class="text-sm text-gray-600 mt-2">1. Add this key to your authenticator app:</p>
                            <code class="block bg-gray-50 border rounded-lg px-3 py-2 text-sm mt-1 select-all tracking-widest">{{ session('totp_secret') }}</code>
                            <p class="text-xs text-gray-400 mt-1 break-all">or use setup link: {{ $uri }}</p>
                            <form method="POST" action="{{ route('security.totp.enable') }}" class="mt-3 flex items-end gap-3 flex-wrap">
                                @csrf
                                <div>
                                    <x-input-label for="code" value="2. Enter the 6-digit code" />
                                    <x-text-input id="code" name="code" class="block mt-1 w-40 tracking-widest" inputmode="numeric" autocomplete="one-time-code" required />
                                </div>
                                <x-btn type="submit" variant="primary">Verify &amp; enable</x-btn>
                            </form>
                        @else
                            <form method="POST" action="{{ route('security.totp.setup') }}" class="mt-2">
                                @csrf
                                <x-btn type="submit" variant="secondary">Set up authenticator</x-btn>
                            </form>
                        @endif
                    </div>

                    {{-- Email OTP --}}
                    <div class="rounded-lg border border-gray-200 p-4">
                        <p class="font-medium text-gray-800">Email code</p>
                        <p class="text-sm text-gray-600 mt-1">Get a 6-digit code by email each time you log in.</p>
                        <form method="POST" action="{{ route('security.email.enable') }}" class="mt-2">
                            @csrf
                            <x-btn type="submit" variant="secondary">Use email codes</x-btn>
                        </form>
                    </div>
                </div>
            @endif
        </x-card>
    </div>
</x-app-layout>
