<?php

namespace App\Http\Controllers;

use App\Models\Contact;
use App\Models\ContactGroup;
use App\Support\ContactCsv;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ContactImportController extends Controller
{
    public function create(): View
    {
        return view('contacts.import', [
            'groups' => ContactGroup::orderBy('name')->get(),
        ]);
    }

    /**
     * Download a ready-to-fill sample file (Name + Number only).
     */
    public function sample(): StreamedResponse
    {
        return response()->streamDownload(function () {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['name', 'number']);
            fputcsv($out, ['Ahmed Ali', '971501234567']);   // UAE — full country code, no + or spaces
            fputcsv($out, ['Sara Khan', '447911123456']);    // UK
            fclose($out);
        }, 'eagle-contacts-sample.csv', ['Content-Type' => 'text/csv']);
    }

    public function store(Request $request): RedirectResponse
    {
        $request->validate([
            'file' => ['required', 'file', 'max:5120', function ($attribute, $value, $fail) {
                if (! in_array(strtolower($value->getClientOriginalExtension()), ['csv', 'txt', 'xls', 'xlsx'], true)) {
                    $fail('Upload a CSV file (or an Excel sheet saved as CSV).');
                }
            }],
            'group_id'     => ['nullable', 'integer', 'exists:contact_groups,id'],
            'new_group'    => ['nullable', 'string', 'max:255'],
        ]);

        $path = $request->file('file')->getRealPath();

        if (ContactCsv::looksBinary($path)) {
            return back()->with('error', 'That looks like an Excel workbook (.xlsx). Open it and use File → Save As → CSV, then upload the .csv.');
        }

        // Resolve target group (existing pick or a new named group).
        $groupId = $request->input('group_id');
        if ($request->filled('new_group')) {
            $groupId = ContactGroup::create(['name' => $request->input('new_group')])->id;
        }

        $rows = ContactCsv::rows($path);

        $imported = 0;
        $duplicates = 0;
        $skipped = 0;
        $seen = [];

        foreach ($rows as $row) {
            $phone = ContactCsv::phone($row);

            if (strlen($phone) < 6) {
                $skipped++;
                continue;
            }

            // Skip duplicates — within the file and against existing contacts.
            $existing = isset($seen[$phone]) ? null : Contact::where('phone', $phone)->first();
            if (isset($seen[$phone]) || $existing) {
                $duplicates++;
                if ($groupId && $existing) {
                    $existing->groups()->syncWithoutDetaching([$groupId]); // still file them under the chosen group
                }
                continue;
            }

            $seen[$phone] = true;

            $contact = Contact::create([
                'phone'   => $phone,
                'name'    => $row['name'] ?? null,
                'email'   => $row['email'] ?? null,
                'country' => $row['country'] ?? null,
            ]);

            if ($groupId) {
                $contact->groups()->syncWithoutDetaching([$groupId]);
            }

            $imported++;
        }

        if ($imported === 0 && $duplicates === 0) {
            return back()->with('error', 'No contacts found. Make sure the file has a "number" column with country codes, saved as CSV. '.($skipped ? "({$skipped} rows had no valid number.)" : ''));
        }

        return redirect()->route('contacts.index')
            ->with('success', "Import complete: {$imported} new contacts, {$duplicates} duplicates skipped, {$skipped} invalid rows skipped.");
    }
}
