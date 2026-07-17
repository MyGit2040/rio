<?php

namespace App\Http\Controllers;

use App\Models\Alert;
use App\Models\Contact;
use App\Models\Invoice;
use App\Models\WhatsappInstance;
use App\Support\Tenancy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Receives WhatsApp shop / checkout-cart submissions and turns them into a
 * pending invoice in the owning workspace, plus a dashboard alert.
 *
 * Expected payload:
 *   { instance, phone, name, currency, items:[{name, quantity, price}] }
 * The `instance` (Evolution instance name) identifies which workspace the order belongs to.
 */
class OrderWebhookController extends Controller
{
    public function handle(Request $request, ?string $secret = null): JsonResponse
    {
        $expected = config('openwa.webhook_secret');
        if ($expected && $secret !== $expected) {
            return response()->json(['ok' => false], 403);
        }

        $instance = WhatsappInstance::withoutGlobalScopes()
            ->where('instance_name', $request->input('instance'))
            ->first();

        if (! $instance) {
            return response()->json(['ok' => false, 'error' => 'unknown instance'], 404);
        }

        $items = $this->extractItems($request);
        if (empty($items)) {
            return response()->json(['ok' => false, 'error' => 'no items'], 422);
        }

        $invoice = Tenancy::run($instance->tenant_id, function () use ($request, $items) {
            $phone = preg_replace('/\D+/', '', (string) $request->input('phone', ''));
            $contact = $phone
                ? Contact::firstOrCreate(['phone' => $phone], ['name' => $request->input('name')])
                : null;

            $total = collect($items)->sum(fn ($i) => $i['quantity'] * $i['price']);
            $number = 'INV-'.now()->format('ymd').'-'.strtoupper(Str::random(5));

            $invoice = Invoice::create([
                'contact_id' => $contact?->id,
                'phone'      => $phone ?: null,
                'number'     => $number,
                'status'     => 'pending',
                'currency'   => strtoupper((string) $request->input('currency', 'USD')),
                'total'      => $total,
                'items'      => $items,
            ]);

            Alert::create([
                'level'   => 'info',
                'title'   => 'New order from '.($request->input('name') ?: ($phone ?: 'a customer')),
                'body'    => "Invoice {$number} — {$invoice->currency} ".number_format($total, 2).' · pending',
                'context' => ['invoice_id' => $invoice->id],
            ]);

            return $invoice;
        });

        return response()->json(['ok' => true, 'invoice' => $invoice->number]);
    }

    /**
     * @return array<int, array{name:string, quantity:int, price:float}>
     */
    private function extractItems(Request $request): array
    {
        $raw = $request->input('items') ?? data_get($request->all(), 'data.order.items', []);

        return collect($raw)->map(fn ($i) => [
            'name'     => (string) ($i['name'] ?? $i['product'] ?? 'Item'),
            'quantity' => (int) ($i['quantity'] ?? $i['qty'] ?? 1),
            'price'    => (float) ($i['price'] ?? $i['amount'] ?? 0),
        ])->filter(fn ($i) => $i['quantity'] > 0)->values()->all();
    }
}
