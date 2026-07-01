<x-admin-layout header="Plans &amp; pricing">
    <div class="bg-white rounded-xl border border-gray-200 shadow-sm overflow-hidden">
        <div class="flex items-center gap-3 px-5 py-4 border-b border-gray-100">
            <h2 class="font-semibold text-gray-800">Subscription plans</h2>
            <span class="text-sm text-gray-500">{{ $plans->count() }} plan(s)</span>
            <a href="{{ route('admin.plans.create') }}" class="ml-auto inline-flex items-center gap-2 px-4 py-2 rounded-lg bg-brand text-white text-sm font-medium">+ New plan</a>
        </div>

        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-gray-50 text-gray-500 text-left">
                    <tr>
                        <th class="px-5 py-3 font-medium">Plan</th>
                        <th class="px-5 py-3 font-medium">Price</th>
                        <th class="px-5 py-3 font-medium">Limits (0 = ∞)</th>
                        <th class="px-5 py-3 font-medium">Workspaces</th>
                        <th class="px-5 py-3 font-medium">Status</th>
                        <th class="px-5 py-3 font-medium text-right">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @forelse ($plans as $plan)
                        <tr class="hover:bg-gray-50">
                            <td class="px-5 py-3">
                                <div class="font-medium text-gray-800 flex items-center gap-2">
                                    {{ $plan->name }}
                                    @if ($plan->is_popular)<span class="px-1.5 py-0.5 rounded-full bg-brand/10 text-brand text-[11px]">Popular</span>@endif
                                    @if ($plan->is_default)<span class="px-1.5 py-0.5 rounded-full bg-amber-100 text-amber-700 text-[11px]">Default</span>@endif
                                </div>
                                <div class="text-xs text-gray-400">{{ $plan->key }}</div>
                            </td>
                            <td class="px-5 py-3 text-gray-600 whitespace-nowrap">
                                ${{ rtrim(rtrim(number_format($plan->price, 2), '0'), '.') }}<span class="text-gray-400">/mo</span>
                            </td>
                            <td class="px-5 py-3 text-gray-600 whitespace-nowrap text-xs">
                                {{ (int) data_get($plan->limits, 'devices', 0) }} dev ·
                                {{ number_format((int) data_get($plan->limits, 'contacts', 0)) }} contacts ·
                                {{ number_format((int) data_get($plan->limits, 'monthly_messages', 0)) }} msg/mo
                            </td>
                            <td class="px-5 py-3 text-gray-600">{{ $counts[$plan->key] ?? 0 }}</td>
                            <td class="px-5 py-3">
                                @if ($plan->is_active)
                                    <span class="px-2 py-0.5 rounded-full bg-green-100 text-green-700 text-xs">Active</span>
                                @else
                                    <span class="px-2 py-0.5 rounded-full bg-gray-100 text-gray-500 text-xs">Hidden</span>
                                @endif
                            </td>
                            <td class="px-5 py-3">
                                <div class="flex items-center gap-3 justify-end">
                                    <a href="{{ route('admin.plans.edit', $plan) }}" class="text-brand hover:underline">Edit</a>
                                    <form method="POST" action="{{ route('admin.plans.destroy', $plan) }}"
                                          onsubmit="return confirm('Delete the {{ $plan->name }} plan?')">
                                        @csrf @method('DELETE')
                                        <button class="text-red-600 hover:text-red-700">Delete</button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="px-5 py-10 text-center text-gray-500">No plans yet. Create your first one.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <p class="mt-4 text-xs text-gray-400">Owners choose from active plans on their Billing page. Limits are enforced live (devices, contacts, monthly messages).</p>
</x-admin-layout>
