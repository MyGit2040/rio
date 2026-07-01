<div x-data="{ matchType: '{{ old('match_type', $rule->match_type ?? 'contains') }}' }" class="space-y-4">
    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
        <div>
            <x-input-label for="name" value="Rule name" />
            <x-text-input id="name" name="name" class="block mt-1 w-full" :value="old('name', $rule->name ?? '')" required />
        </div>
        <div>
            <x-input-label for="priority" value="Priority (lower runs first)" />
            <x-text-input id="priority" name="priority" type="number" min="1" max="9999" class="block mt-1 w-full" :value="old('priority', $rule->priority ?? 100)" required />
        </div>
    </div>

    <div>
        <x-input-label for="whatsapp_instance_id" value="Applies to device" />
        <select id="whatsapp_instance_id" name="whatsapp_instance_id" class="mt-1 block w-full rounded-lg border-gray-300 text-sm focus:ring-green-500 focus:border-green-500">
            <option value="">All devices</option>
            @foreach ($devices as $device)
                <option value="{{ $device->id }}" @selected(old('whatsapp_instance_id', $rule->whatsapp_instance_id ?? '') == $device->id)>{{ $device->name }}</option>
            @endforeach
        </select>
    </div>

    <div>
        <x-input-label for="match_type" value="Trigger" />
        <select id="match_type" name="match_type" x-model="matchType" class="mt-1 block w-full rounded-lg border-gray-300 text-sm focus:ring-green-500 focus:border-green-500">
            <option value="contains">Message contains keyword</option>
            <option value="exact">Message is exactly</option>
            <option value="starts_with">Message starts with</option>
            <option value="any">Any incoming message</option>
            <option value="ai">AI decides (always replies with AI)</option>
        </select>
    </div>

    <div x-show="['contains','exact','starts_with'].includes(matchType)" x-cloak>
        <x-input-label for="keywords" value="Keywords (comma separated)" />
        <x-text-input id="keywords" name="keywords" class="block mt-1 w-full" placeholder="hi, hello, price" :value="old('keywords', $rule->keywords ?? '')" />
    </div>

    <div>
        <x-input-label for="reply">
            <span x-text="matchType === 'ai' ? 'AI instructions (system prompt)' : 'Reply message'"></span>
        </x-input-label>
        <textarea id="reply" name="reply" rows="4"
                  class="mt-1 block w-full rounded-lg border-gray-300 text-sm focus:ring-green-500 focus:border-green-500"
                  placeholder="Thanks for your message! ...">{{ old('reply', $rule->reply ?? '') }}</textarea>
    </div>

    <div class="flex items-center gap-6">
        <label class="inline-flex items-center gap-2" x-show="matchType !== 'ai'">
            <input type="hidden" name="use_ai" value="0">
            <input type="checkbox" name="use_ai" value="1" @checked(old('use_ai', $rule->use_ai ?? false))
                   class="rounded border-gray-300 text-green-600 focus:ring-green-500">
            <span class="text-sm text-gray-700">Generate reply with AI</span>
        </label>
        <label class="inline-flex items-center gap-2">
            <input type="hidden" name="is_active" value="0">
            <input type="checkbox" name="is_active" value="1" @checked(old('is_active', $rule->is_active ?? true))
                   class="rounded border-gray-300 text-green-600 focus:ring-green-500">
            <span class="text-sm text-gray-700">Active</span>
        </label>
    </div>
</div>
