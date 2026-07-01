{{-- Password field with a show/hide (eye) toggle. Drop-in for <x-text-input type="password">.
     Pass name/id/required/autocomplete/placeholder as attributes; the wrapper owns spacing. --}}
<div class="relative mt-1" x-data="{ show: false }">
    <input x-bind:type="show ? 'text' : 'password'"
           {{ $attributes->merge(['class' => 'block w-full rounded-lg border-gray-300 pr-10 text-sm shadow-sm focus:ring-brand focus:border-brand']) }}>
    <button type="button" tabindex="-1" @click="show = ! show"
            :aria-label="show ? 'Hide password' : 'Show password'"
            class="absolute inset-y-0 right-0 flex items-center px-3 text-gray-400 hover:text-gray-600">
        {{-- eye (reveal) --}}
        <svg x-show="!show" class="w-[18px] h-[18px]" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/></svg>
        {{-- eye-off (hide) — inline display:none avoids a flash before Alpine loads --}}
        <svg x-show="show" style="display:none" class="w-[18px] h-[18px]" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.875 18.825A10.05 10.05 0 0112 19c-4.478 0-8.268-2.943-9.543-7a9.97 9.97 0 011.563-3.029m5.858.908a3 3 0 114.243 4.243M9.878 9.878l4.242 4.242M9.88 9.88l-3.29-3.29m7.532 7.532l3.29 3.29M3 3l3.59 3.59m0 0A9.953 9.953 0 0112 5c4.478 0 8.268 2.943 9.543 7a10.025 10.025 0 01-4.132 5.411m0 0L21 21"/></svg>
    </button>
</div>
