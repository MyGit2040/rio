<?php

namespace App\Http\Controllers;

use App\Models\Invoice;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class InvoiceController extends Controller
{
    public function index(Request $request): View
    {
        $invoices = Invoice::with('contact')
            ->when($request->filled('q'), fn ($query) => $query->where(fn ($w) =>
                $w->where('number', 'like', '%'.$request->input('q').'%')
                  ->orWhere('phone', 'like', '%'.$request->input('q').'%')))
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->input('status')))
            ->latest()
            ->paginate(20)
            ->withQueryString();

        return view('invoices.index', compact('invoices'));
    }

    public function updateStatus(Request $request, Invoice $invoice): RedirectResponse
    {
        $data = $request->validate([
            'status' => ['required', 'in:pending,paid,cancelled'],
        ]);

        $invoice->update($data);

        return back()->with('success', "Invoice {$invoice->number} marked {$data['status']}.");
    }
}
