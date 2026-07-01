<?php

namespace App\Http\Controllers;

use App\Jobs\VerifyContactsBatch;
use App\Models\Contact;
use App\Models\ContactGroup;
use App\Models\WhatsappInstance;
use App\Support\ContactCsv;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class GroupController extends Controller
{
    public function index(Request $request): View
    {
        $groups = ContactGroup::withCount('contacts')
            ->when($request->filled('q'), fn ($query) => $query->where('name', 'like', '%'.$request->input('q').'%'))
            ->orderBy('name')
            ->get();

        return view('groups.index', compact('groups'));
    }

    public function create(): View
    {
        return view('groups.create');
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);
        ContactGroup::create($data);

        return redirect()->route('groups.index')->with('success', 'Group created.');
    }

    public function show(ContactGroup $group): View
    {
        $contacts = $group->contacts()->orderBy('name')->paginate(30);

        $counts = [
            'total'      => $group->contacts()->count(),
            'valid'      => $group->contacts()->where('wa_status', 'valid')->count(),
            'unverified' => $group->contacts()->where('wa_status', 'unverified')->count(),
        ];

        return view('groups.show', compact('group', 'contacts', 'counts'));
    }

    public function import(Request $request, ContactGroup $group): RedirectResponse
    {
        $request->validate(['file' => ['required', 'file', 'max:5120', $this->csvExtension()]]);

        $path = $request->file('file')->getRealPath();

        if (ContactCsv::looksBinary($path)) {
            return back()->with('error', 'That looks like an Excel workbook (.xlsx). Open it and use File → Save As → CSV, then upload the .csv.');
        }

        $new = 0;
        $existing = 0;
        $skipped = 0;

        foreach (ContactCsv::rows($path) as $row) {
            $phone = ContactCsv::phone($row);
            if (strlen($phone) < 6) {
                $skipped++;
                continue;
            }

            // Skip duplicate data: keep an existing contact as-is, just add it to the group.
            $contact = Contact::firstOrCreate(
                ['phone' => $phone],
                ['name' => $row['name'] ?? null, 'email' => $row['email'] ?? null, 'country' => $row['country'] ?? null],
            );
            $contact->wasRecentlyCreated ? $new++ : $existing++;
            $contact->groups()->syncWithoutDetaching([$group->id]);
        }

        if ($new === 0 && $existing === 0) {
            return back()->with('error', 'No contacts found. Check the file has a "number" column with country codes, saved as CSV. '.($skipped ? "({$skipped} rows had no valid number.)" : ''));
        }

        return back()->with('success', "Imported into {$group->name}: {$new} new, {$existing} already existed (added to group), {$skipped} invalid rows skipped.");
    }

    /**
     * Validate by real filename extension (avoids Excel's misleading CSV mime type).
     */
    private function csvExtension(): \Closure
    {
        return function ($attribute, $value, $fail) {
            if (! in_array(strtolower($value->getClientOriginalExtension()), ['csv', 'txt', 'xls', 'xlsx'], true)) {
                $fail('Upload a CSV file (or an Excel sheet saved as CSV).');
            }
        };
    }

    public function verify(ContactGroup $group): RedirectResponse
    {
        if (! WhatsappInstance::where('status', 'open')->exists()) {
            return back()->with('error', 'Connect a WhatsApp device first — verification runs through a linked number.');
        }

        VerifyContactsBatch::dispatch($group->id);

        return back()->with('success', 'Verifying WhatsApp numbers in the background, gently in small batches. Refresh in a moment to watch progress.');
    }

    public function edit(ContactGroup $group): View
    {
        return view('groups.edit', compact('group'));
    }

    public function update(Request $request, ContactGroup $group): RedirectResponse
    {
        $group->update($this->validated($request));

        return redirect()->route('groups.index')->with('success', 'Group updated.');
    }

    public function destroy(ContactGroup $group): RedirectResponse
    {
        $group->delete();

        return redirect()->route('groups.index')->with('success', 'Group deleted.');
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'name'  => ['required', 'string', 'max:255'],
            'color' => ['nullable', 'string', 'max:9'],
        ]);
    }
}

