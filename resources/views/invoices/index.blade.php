<x-app-layout>
    <x-slot name="header">Orders</x-slot>

    <x-card flush>
        <div class="px-5 py-4 border-b border-gray-100">
            <h2 class="font-semibold text-gray-800">Orders &amp; invoices</h2>
            <p class="text-sm text-gray-500">Created automatically from WhatsApp shop checkouts.</p>
        </div>

        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-gray-50 text-gray-500 text-left">
                    <tr>
                        <th class="px-5 py-3 font-medium">Invoice</th>
                        <th class="px-5 py-3 font-medium">Customer</th>
                        <th class="px-5 py-3 font-medium">Items</th>
                        <th class="px-5 py-3 font-medium">Total</th>
                        <th class="px-5 py-3 font-medium">Status</th>
                        <th class="px-5 py-3 font-medium text-right">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @forelse ($invoices as $invoice)
                        @php $sc = ['pending' => 'yellow', 'paid' => 'green', 'cancelled' => 'red'][$invoice->status] ?? 'gray'; @endphp
                        <tr class="hover:bg-gray-50">
                            <td class="px-5 py-3 font-medium text-gray-800 whitespace-nowrap">{{ $invoice->number }}</td>
                            <td class="px-5 py-3 text-gray-600 whitespace-nowrap">{{ $invoice->contact->name ?? ($invoice->phone ? '+'.$invoice->phone : '—') }}</td>
                            <td class="px-5 py-3 text-gray-500">{{ collect($invoice->items)->sum('quantity') }} item(s)</td>
                            <td class="px-5 py-3 text-gray-800 whitespace-nowrap">{{ $invoice->currency }} {{ number_format($invoice->total, 2) }}</td>
                            <td class="px-5 py-3"><x-badge :color="$sc">{{ ucfirst($invoice->status) }}</x-badge></td>
                            <td class="px-5 py-3">
                                <div class="flex items-center gap-2 justify-end">
                                    @if ($invoice->status !== 'paid')
                                        <form method="POST" action="{{ route('invoices.status', $invoice) }}">
                                            @csrf @method('PATCH')
                                            <input type="hidden" name="status" value="paid">
                                            <button class="text-green-600 hover:text-green-700">Mark paid</button>
                                        </form>
                                    @endif
                                    @if ($invoice->status === 'pending')
                                        <form method="POST" action="{{ route('invoices.status', $invoice) }}">
                                            @csrf @method('PATCH')
                                            <input type="hidden" name="status" value="cancelled">
                                            <button class="text-red-600 hover:text-red-700">Cancel</button>
                                        </form>
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="px-5 py-10 text-center text-gray-500">No orders yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if ($invoices->hasPages())
            <div class="px-5 py-3 border-t border-gray-100">{{ $invoices->links() }}</div>
        @endif
    </x-card>
</x-app-layout>
