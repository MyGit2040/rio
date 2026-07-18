<?php

namespace App\Http\Controllers;

use App\Http\Requests\ContactRequest;
use App\Models\Contact;
use App\Models\ContactGroup;
use App\Models\WhatsappInstance;
use App\Services\PlanLimit;
use App\Support\Whatsapp;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ContactController extends Controller
{
    public function index(Request $request): View
    {
        $contacts = Contact::query()
            ->with('groups')
            ->search($request->input('q'))
            ->when($request->filled('group'), fn ($query) =>
                $query->whereHas('groups', fn ($g) => $g->where('contact_groups.id', $request->input('group'))))
            ->when($request->filled('tag'), fn ($query) => $query->tagged($request->input('tag')))
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
        if (PlanLimit::for(auth()->user()->tenant)->reached('contacts')) {
            return back()->withInput()->with('error', 'You have reached your plan\'s contact limit. Upgrade in Billing to add more.');
        }

        $data = $this->consentPayload($request->safe()->except('groups'));
        $contact = Contact::create($data);
        $contact->groups()->sync($request->input('groups', []));

        return redirect()->route('contacts.index')->with('success', 'Contact added.');
    }

    /**
     * Contact profile with an activity timeline.
     */
    public function show(Contact $contact): View
    {
        $contact->load('groups');

        $timeline = $contact->messages()
            ->with('instance')
            ->latest('id')
            ->limit(100)
            ->get();

        return view('contacts.show', compact('contact', 'timeline'));
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
        $contact->update($this->consentPayload($request->safe()->except('groups'), $contact));
        $contact->groups()->sync($request->input('groups', []));

        return redirect()->route('contacts.index')->with('success', 'Contact updated.');
    }

    public function destroy(Contact $contact): RedirectResponse
    {
        $contact->delete();

        return redirect()->route('contacts.index')->with('success', 'Contact deleted.');
    }

    /**
     * Bulk actions on selected contacts: delete, add/remove group, opt-out.
     */
    public function bulk(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'action'   => ['required', 'in:delete,add_group,remove_group,opt_out,opt_in,record_permission'],
            'ids'      => ['required', 'array', 'min:1'],
            'ids.*'    => ['integer'],
            'group_id' => ['nullable', 'integer', 'exists:contact_groups,id'],
            'marketing_consent_source' => ['nullable', 'string', 'max:100', 'required_if:action,record_permission'],
        ]);

        $query = Contact::whereIn('id', $data['ids']); // tenant-scoped by the global scope
        $count = (clone $query)->count();

        if (in_array($data['action'], ['add_group', 'remove_group'], true) && empty($data['group_id'])) {
            return back()->with('error', 'Pick a group first.');
        }

        switch ($data['action']) {
            case 'delete':
                $query->delete();
                $msg = "{$count} contact(s) deleted.";
                break;
            case 'add_group':
                (clone $query)->get()->each(fn ($c) => $c->groups()->syncWithoutDetaching([$data['group_id']]));
                $msg = "{$count} contact(s) added to the group.";
                break;
            case 'remove_group':
                (clone $query)->get()->each(fn ($c) => $c->groups()->detach($data['group_id']));
                $msg = "{$count} contact(s) removed from the group.";
                break;
            case 'opt_out':
                $query->update(['opted_out' => true, 'marketing_opted_in' => false]);
                $msg = "{$count} contact(s) opted out.";
                break;
            case 'record_permission':
                // Never silently reverse an opt-out. Only active contacts can
                // receive a documented marketing-permission record.
                $updated = $query->where('opted_out', false)->update([
                    'marketing_opted_in' => true,
                    'marketing_opted_in_at' => now(),
                    'marketing_consent_source' => $data['marketing_consent_source'],
                ]);
                $msg = "Permission recorded for {$updated} contact(s). Opted-out contacts were left unchanged.";
                break;
            default: // opt_in
                $query->update(['opted_out' => false]);
                $msg = "{$count} contact(s) reactivated. Record documented permission on each contact before marketing messages can be sent.";
        }

        return back()->with('success', $msg);
    }

    /**
     * Export the current (filtered) contact list as a CSV.
     */
    public function export(Request $request): StreamedResponse
    {
        $query = Contact::query()
            ->with('groups')
            ->search($request->input('q'))
            ->when($request->filled('group'), fn ($q) => $q->whereHas('groups', fn ($g) => $g->where('contact_groups.id', $request->input('group'))))
            ->when($request->filled('tag'), fn ($q) => $q->tagged($request->input('tag')))
            ->when($request->input('status') === 'opted_out', fn ($q) => $q->where('opted_out', true))
            ->when($request->input('status') === 'active', fn ($q) => $q->where('opted_out', false))
            ->latest();

        return response()->streamDownload(function () use ($query) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['name', 'number', 'email', 'country', 'tags', 'status', 'whatsapp']);

            $query->chunk(500, function ($contacts) use ($out) {
                foreach ($contacts as $c) {
                    fputcsv($out, [
                        $c->name, $c->phone, $c->email, $c->country,
                        collect($c->tags ?? [])->join(', '),
                        $c->opted_out ? 'opted_out' : 'active',
                        $c->wa_status ?? 'unverified',
                    ]);
                }
            });

            fclose($out);
        }, 'contacts-'.now()->format('Y-m-d').'.csv', ['Content-Type' => 'text/csv']);
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

        $engine = Whatsapp::forInstance($device);

        if (! $engine->configured()) {
            return back()->with('error', 'Configure OpenWA in Settings first.');
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

    /** @param array<string, mixed> $data */
    private function consentPayload(array $data, ?Contact $existing = null): array
    {
        if (($data['opted_out'] ?? false) === true) {
            $data['marketing_opted_in'] = false;
            $data['marketing_opted_in_at'] = null;
            $data['marketing_consent_source'] = null;

            return $data;
        }

        if (($data['marketing_opted_in'] ?? false) === true) {
            $data['marketing_opted_in_at'] = $existing?->marketing_opted_in_at ?? now();
        } elseif (array_key_exists('marketing_opted_in', $data)) {
            $data['marketing_opted_in_at'] = null;
            $data['marketing_consent_source'] = null;
        }

        return $data;
    }
}
