<x-app-layout>
    <x-slot name="header">New chatbot rule</x-slot>

    <div class="max-w-2xl">
        <x-card title="New rule">
            <form method="POST" action="{{ route('chatbot.store') }}">
                @csrf
                @include('chatbot._form')
                <div class="mt-6 flex items-center gap-3">
                    <x-btn type="submit" variant="primary">Save rule</x-btn>
                    <x-btn :href="route('chatbot.index')" variant="ghost">Cancel</x-btn>
                </div>
            </form>
        </x-card>
    </div>
</x-app-layout>
