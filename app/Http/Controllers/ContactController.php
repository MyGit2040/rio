<?php

namespace App\Http\Controllers;

use App\Http\Requests\ContactRequest;
use App\Models\Contact;
use App\Models\ContactGroup;
use App\Models\WhatsappInstance;
use App\Services\EvolutionApiService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;

class ContactController extends Controller
{
    public function index(Request $request): View
    {
        $contacts = Contact::query()
            ->with('groups')
            ->search($request->input('q'))
            ->when($request->filled('group'), fn ($query) =>
                $query->whereHas('groups', fn ($g) => $g->where('contact_groups.id', $request->input('group'))))
            ->when($request->input('status') === 'opted_out', fn ($query) => $query->where('opted_out', true))
            ->when($request->input('status') === 'active', fn ($query) => $query->where('opted_out', false))
            ->latest()
            ->paginate(20)
            ->withQueryString();

        $groups = ContactGroup::orderBy('name')->get();

        return view('contacts.index', compact('contacts', 'groups'));
    }

    public function create(): View
    {
        return view('contacts.create', [
            'groups' => ContactGroup::orderBy('name')->get(),
        ]);
    }

    public function store(ContactRequest $request): RedirectResponse
    {
        $contact = Contact::create($request->safe()->except('groups'));
        $contact->groups()->sync($request->input('groups', []));

        return redirect()->route('contacts.index')->with('success', 'Contact added.');
    }

    public function edit(Contact $contact): View
    {
        return view('contacts.edit', [
            'contact' => $contact->load('groups'),
            'groups'  => ContactGroup::orderBy('name')->get(),
        ]);
    }

    public function update(ContactRequest $request, Contact $contact): RedirectResponse
    {
        $contact->update($request->safe()->except('groups'));
        $contact->groups()->sync($request->input('groups', []));

        return redirect()->route('contacts.index')->with('success', 'Contact updated.');
    }

    public function destroy(Contact $contact): RedirectResponse
    {
        $contact->delete();

        return redirect()->route('contacts.index')->with('success', 'Contact deleted.');
    }

    /**
     * Check unverified contacts against WhatsApp and mark them valid/invalid.
     */
    public function verify(Request $request): RedirectResponse
    {
        $device = WhatsappInstance::where('status', 'open')->first();

        if (! $device) {
            return back()->with('error', 'Connect a WhatsApp device first — verification runs through a linked number.');
        }

        $engine = EvolutionApiService::forInstance($device);

        if (! $engine->configured()) {
            return back()->with('error', 'Configure the Evolution engine in Settings first.');
        }

        $contacts = Contact::where('wa_status', 'unverified')
            ->when($request->filled('group'), fn ($q) =>
                $q->whereHas('groups', fn ($g) => $g->where('contact_groups.id', $request->input('group'))))
            ->limit(500)
            ->get(['id', 'phone']);

        if ($contacts->isEmpty()) {
            return back()->with('success', 'No unverified contacts to check.');
        }

        $valid = 0;
        $invalid = 0;

        foreach ($contacts->chunk(50) as $chunk) {
            try {
                $result = $engine->checkNumbers($device->instance_name, $chunk->pluck('phone')->all());
            } catch (\Throwable $e) {
                Log::error('Number verification failed', ['error' => $e->getMessage()]);

                return back()->with('error', 'Verification failed: '.$e->getMessage());
            }

            $existsByNumber = collect($result)->keyBy(
                fn ($row) => preg_replace('/\D+/', '', (string) ($row['number'] ?? ''))
            );

            foreach ($chunk as $contact) {
                $exists = (bool) data_get($existsByNumber->get($contact->phone), 'exists', false);
                $contact->update(['wa_status' => $exists ? 'valid' : 'invalid', 'verified_at' => now()]);
                $exists ? $valid++ : $invalid++;
            }
        }

        return back()->with('success', "Verification complete: {$valid} on WhatsApp, {$invalid} not found.");
    }
}
