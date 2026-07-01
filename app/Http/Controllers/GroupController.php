<?php

namespace App\Http\Controllers;

use App\Jobs\VerifyContactsBatch;
use App\Models\Contact;
use App\Models\ContactGroup;
use App\Models\WhatsappInstance;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class GroupController extends Controller
{
    public function index(): View
    {
        $groups = ContactGroup::withCount('contacts')->orderBy('name')->get();

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
        $request->validate(['file' => ['required', 'file', 'mimes:csv,txt', 'max:5120']]);

        $imported = 0;
        foreach ($this->readCsv($request->file('file')->getRealPath()) as $row) {
            $phone = preg_replace('/\D+/', '', (string) ($row['phone'] ?? ''));
            if (strlen($phone) < 6) {
                continue;
            }
            $contact = Contact::updateOrCreate(
                ['phone' => $phone],
                ['name' => $row['name'] ?? null, 'email' => $row['email'] ?? null, 'country' => $row['country'] ?? null],
            );
            $contact->groups()->syncWithoutDetaching([$group->id]);
            $imported++;
        }

        return back()->with('success', "Imported {$imported} contacts into {$group->name}.");
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

    /**
     * @return array<int, array<string, ?string>>
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
            if ($header === null) {
                $header = array_map(fn ($h) => str_replace(' ', '_', strtolower(trim((string) $h))), $data);

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

