<?php

namespace App\Http\Controllers;

use App\Models\Suppression;
use App\Support\Audit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class SuppressionController extends Controller
{
    public function index(Request $request): View
    {
        $suppressions = Suppression::query()
            ->when($request->filled('q'), fn ($query) => $query->where('phone', 'like', '%'.preg_replace('/\D+/', '', $request->input('q')).'%'))
            ->when($request->filled('source'), fn ($query) => $query->where('source', $request->input('source')))
            ->when($request->filled('created_from'), fn ($query) => $query->whereDate('created_at', '>=', $request->input('created_from')))
            ->when($request->filled('created_to'), fn ($query) => $query->whereDate('created_at', '<=', $request->input('created_to')))
            ->latest()
            ->paginate(30)
            ->withQueryString();

        return view('suppressions.index', compact('suppressions'));
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'phone'  => ['required', 'string', 'max:32'],
            'reason' => ['nullable', 'string', 'max:255'],
        ]);

        $phone = preg_replace('/\D+/', '', $data['phone']);

        Suppression::updateOrCreate(
            ['tenant_id' => auth()->user()->tenant_id, 'phone' => $phone],
            ['reason' => $data['reason'] ?? null, 'source' => 'manual'],
        );

        Audit::log('suppression.added', null, "Blocked +{$phone}");

        return back()->with('success', "+{$phone} added to the do-not-contact list.");
    }

    /**
     * Bulk import phone numbers (one per line or CSV first column).
     */
    public function import(Request $request): RedirectResponse
    {
        $request->validate(['numbers' => ['required', 'string', 'max:100000']]);

        $lines = preg_split('/[\r\n,]+/', $request->input('numbers'));
        $added = 0;

        foreach ($lines as $line) {
            $phone = preg_replace('/\D+/', '', $line);
            if (strlen($phone) < 6) {
                continue;
            }

            Suppression::updateOrCreate(
                ['tenant_id' => auth()->user()->tenant_id, 'phone' => $phone],
                ['source' => 'import'],
            );
            $added++;
        }

        Audit::log('suppression.imported', null, "{$added} numbers");

        return back()->with('success', "{$added} number(s) added to the do-not-contact list.");
    }

    public function destroy(Suppression $suppression): RedirectResponse
    {
        $phone = $suppression->phone;
        $suppression->delete();

        Audit::log('suppression.removed', null, "Unblocked +{$phone}");

        return back()->with('success', "+{$phone} removed from the do-not-contact list.");
    }

    public function bulk(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'action' => ['required', 'in:delete'],
            'ids'    => ['required', 'array', 'min:1'],
            'ids.*'  => ['integer'],
        ]);

        $count = Suppression::whereIn('id', $data['ids'])->count();
        Suppression::whereIn('id', $data['ids'])->delete();
        Audit::log('suppression.removed', null, "Bulk-unblocked {$count} number(s)");

        return back()->with('success', "{$count} number(s) removed from the do-not-contact list.");
    }
}
