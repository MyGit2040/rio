<?php

namespace App\Http\Controllers;

use App\Models\Contact;
use App\Models\ContactGroup;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ContactImportController extends Controller
{
    public function create(): View
    {
        return view('contacts.import', [
            'groups' => ContactGroup::orderBy('name')->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $request->validate([
            'file'         => ['required', 'file', 'mimes:csv,txt', 'max:5120'],
            'group_id'     => ['nullable', 'integer', 'exists:contact_groups,id'],
            'new_group'    => ['nullable', 'string', 'max:255'],
        ]);

        // Resolve target group (existing pick or a new named group).
        $groupId = $request->input('group_id');
        if ($request->filled('new_group')) {
            $groupId = ContactGroup::create(['name' => $request->input('new_group')])->id;
        }

        $rows = $this->readCsv($request->file('file')->getRealPath());

        $imported = 0;
        $skipped = 0;

        foreach ($rows as $row) {
            $phone = preg_replace('/\D+/', '', (string) ($row['phone'] ?? ''));

            if (strlen($phone) < 6) {
                $skipped++;
                continue;
            }

            $contact = Contact::updateOrCreate(
                ['phone' => $phone],
                [
                    'name'    => $row['name'] ?? null,
                    'email'   => $row['email'] ?? null,
                    'country' => $row['country'] ?? null,
                ]
            );

            if ($groupId) {
                $contact->groups()->syncWithoutDetaching([$groupId]);
            }

            $imported++;
        }

        return redirect()->route('contacts.index')
            ->with('success', "Import complete: {$imported} contacts added/updated, {$skipped} rows skipped.");
    }

    /**
     * Read a CSV into an array of header-keyed rows.
     *
     * @return array<int, array<string, string>>
     */
    private function readCsv(string $path): array
    {
        $rows = [];
        $handle = fopen($path, 'r');

        if ($handle === false) {
            return $rows;
        }

        $header = null;

        while (($data = fgetcsv($handle, 0, ',')) !== false) {
            // Normalise header names: lowercase, trim, spaces -> underscores.
            if ($header === null) {
                $header = array_map(
                    fn ($h) => str_replace(' ', '_', strtolower(trim((string) $h))),
                    $data
                );
                continue;
            }

            $row = [];
            foreach ($header as $i => $key) {
                $row[$key] = isset($data[$i]) ? trim((string) $data[$i]) : null;
            }
            $rows[] = $row;
        }

        fclose($handle);

        return $rows;
    }
}
