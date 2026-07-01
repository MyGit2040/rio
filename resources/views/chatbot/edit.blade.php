<x-app-layout>
    <x-slot name="header">Edit chatbot rule</x-slot>

    <div class="max-w-2xl">
        <x-card title="Edit rule">
            <form method="POST" action="{{ route('chatbot.update', $rule) }}">
                @csrf @method('PUT')
                @include('chatbot._form')
                <div class="mt-6 flex items-center gap-3">
                    <x-btn type="submit" variant="primary">Update rule</x-btn>
                    <x-btn :href="route('chatbot.index')" variant="ghost">Cancel</x-btn>
                </div>
            </form>
        </x-card>
    </div>
</x-app-layout>
