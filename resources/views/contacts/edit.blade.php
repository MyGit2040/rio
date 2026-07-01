<x-app-layout>
    <x-slot name="header">Edit contact</x-slot>

    <div class="max-w-2xl">
        <x-card title="Edit contact">
            <form method="POST" action="{{ route('contacts.update', $contact) }}">
                @csrf @method('PUT')
                @include('contacts._form')
                <div class="mt-6 flex items-center gap-3">
                    <x-btn type="submit" variant="primary">Update contact</x-btn>
                    <x-btn :href="route('contacts.index')" variant="ghost">Cancel</x-btn>
                </div>
            </form>
        </x-card>
    </div>
</x-app-layout>
