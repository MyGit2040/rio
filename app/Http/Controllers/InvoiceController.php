<?php

namespace App\Http\Controllers;

use App\Models\Invoice;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class InvoiceController extends Controller
{
    public function index(): View
    {
        $invoices = Invoice::with('contact')->latest()->paginate(20);

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
