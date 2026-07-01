<?php

namespace App\Http\Controllers;

use App\Models\ContactGroup;
use App\Models\Sequence;
use App\Models\Template;
use App\Models\WhatsappInstance;
use App\Services\SequenceService;
use App\Support\Audit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class SequenceController extends Controller
{
    public function __construct(private readonly SequenceService $sequences)
    {
    }

    public function index(Request $request): View
    {
        $sequences = Sequence::withCount(['steps', 'enrollments'])
            ->when($request->filled('q'), fn ($query) => $query->where('name', 'like', '%'.$request->input('q').'%'))
            ->when($request->input('status') === 'active', fn ($query) => $query->where('is_active', true))
            ->when($request->input('status') === 'paused', fn ($query) => $query->where('is_active', false))
            ->latest()
            ->paginate(20)
            ->withQueryString();

        return view('sequences.index', compact('sequences'));
    }

    public function create(): View
    {
        return view('sequences.create', [
            'sequence'  => new Sequence(['is_active' => true]),
            'devices'   => WhatsappInstance::orderBy('name')->get(),
            'templates' => Template::orderBy('name')->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $sequence = $this->sequences->save($this->validated($request));
        Audit::log('sequence.created', $sequence, $sequence->name);

        return redirect()->route('sequences.show', $sequence)->with('success', 'Sequence created — enroll contacts to start.');
    }

    public function show(Sequence $sequence): View
    {
        $sequence->load('steps.template', 'instance');

        return view('sequences.show', [
            'sequence'    => $sequence,
            'groups'      => ContactGroup::orderBy('name')->get(),
            'enrollments' => $sequence->enrollments()->with('contact')->latest()->paginate(20),
            'stats'       => [
                'active'    => $sequence->enrollments()->where('status', 'active')->count(),
                'completed' => $sequence->enrollments()->where('status', 'completed')->count(),
                'stopped'   => $sequence->enrollments()->where('status', 'stopped')->count(),
            ],
        ]);
    }

    public function edit(Sequence $sequence): View
    {
        return view('sequences.edit', [
            'sequence'  => $sequence->load('steps'),
            'devices'   => WhatsappInstance::orderBy('name')->get(),
            'templates' => Template::orderBy('name')->get(),
        ]);
    }

    public function update(Request $request, Sequence $sequence): RedirectResponse
    {
        $this->sequences->save($this->validated($request), $sequence);
        Audit::log('sequence.updated', $sequence, $sequence->name);

        return redirect()->route('sequences.show', $sequence)->with('success', 'Sequence updated.');
    }

    public function destroy(Sequence $sequence): RedirectResponse
    {
        $sequence->delete();
        Audit::log('sequence.deleted', $sequence, $sequence->name);

        return redirect()->route('sequences.index')->with('success', 'Sequence deleted.');
    }

    public function bulk(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'action' => ['required', 'in:delete'],
            'ids'    => ['required', 'array', 'min:1'],
            'ids.*'  => ['integer'],
        ]);

        $count = Sequence::whereIn('id', $data['ids'])->count();
        Sequence::whereIn('id', $data['ids'])->delete();
        Audit::log('sequence.bulk_deleted', null, "Deleted {$count} sequence(s)");

        return back()->with('success', "{$count} sequence(s) deleted.");
    }

    public function enroll(Request $request, Sequence $sequence): RedirectResponse
    {
        $data = $request->validate([
            'group_id' => ['nullable', 'integer', 'exists:contact_groups,id'],
        ]);

        $count = $this->sequences->enroll($sequence, $data['group_id'] ?? null);
        Audit::log('sequence.enrolled', $sequence, "{$count} contacts");

        return back()->with('success', "{$count} contact(s) enrolled.");
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request): array
    {
        $data = $request->validate([
            'name'                    => ['required', 'string', 'max:255'],
            'whatsapp_instance_id'    => ['nullable', 'integer', 'exists:whatsapp_instances,id'],
            'is_active'               => ['sometimes', 'boolean'],
            'steps'                   => ['required', 'array', 'min:1'],
            'steps.*.delay_value'     => ['required', 'integer', 'min:0', 'max:100000'],
            'steps.*.delay_unit'      => ['required', 'in:minutes,hours,days,weeks,months'],
            'steps.*.template_id'     => ['nullable', 'integer', 'exists:templates,id'],
            'steps.*.body'            => ['nullable', 'string', 'max:4096'],
        ]);

        // Convert the chosen value + unit back to delay_minutes (DB + scheduler unchanged).
        $mult = ['minutes' => 1, 'hours' => 60, 'days' => 1440, 'weeks' => 10080, 'months' => 43200];
        foreach ($data['steps'] as &$step) {
            $step['delay_minutes'] = min(525600, (int) $step['delay_value'] * ($mult[$step['delay_unit']] ?? 1));
            unset($step['delay_value'], $step['delay_unit']);
        }

        return $data;
    }
}
