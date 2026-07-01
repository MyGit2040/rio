<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Plan;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WhatsappInstance;
use App\Services\WorkspaceService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class WorkspaceController extends Controller
{
    public function __construct(private readonly WorkspaceService $workspaces)
    {
    }

    public function index(Request $request): View
    {
        $tenants = Tenant::query()
            ->withCount('users')
            ->when($request->filled('q'), fn ($query) => $query->where('name', 'like', '%'.$request->input('q').'%'))
            ->when($request->input('status') === 'suspended', fn ($query) => $query->where('status', 'suspended'))
            ->when($request->input('status') === 'expired', fn ($query) => $query->whereNotNull('expires_at')->where('expires_at', '<', now()))
            ->when($request->input('status') === 'active', fn ($query) => $query->where('status', '!=', 'suspended')
                ->where(fn ($w) => $w->whereNull('expires_at')->orWhere('expires_at', '>=', now())))
            ->latest()
            ->paginate(20)
            ->withQueryString();

        // Device counts across tenants (device model is tenant-scoped, so bypass the scope).
        $deviceCounts = WhatsappInstance::withoutGlobalScopes()
            ->selectRaw('tenant_id, COUNT(*) as c')->groupBy('tenant_id')->pluck('c', 'tenant_id');

        // Owner email per tenant for the list.
        $owners = User::where('role', 'owner')
            ->whereIn('tenant_id', $tenants->pluck('id'))
            ->get()->keyBy('tenant_id');

        return view('admin.workspaces.index', compact('tenants', 'deviceCounts', 'owners'));
    }

    public function create(): View
    {
        return view('admin.workspaces.create', [
            'planTypes' => WorkspaceService::PLAN_TYPES,
            'plans'     => Plan::active()->ordered()->get(),
            'modules'   => config('modules'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validateWorkspace($request, true);

        $tenant = $this->workspaces->create($data);

        return redirect()->route('admin.workspaces.index')
            ->with('success', "Workspace \"{$tenant->name}\" created — the owner can now log in.");
    }

    public function edit(Tenant $workspace): View
    {
        return view('admin.workspaces.edit', [
            'workspace' => $workspace,
            'owner'     => $workspace->users()->where('role', 'owner')->orderBy('id')->first(),
            'deviceCount' => WhatsappInstance::withoutGlobalScopes()->where('tenant_id', $workspace->id)->count(),
            'planTypes' => WorkspaceService::PLAN_TYPES,
            'plans'     => Plan::active()->ordered()->get(),
            'modules'   => config('modules'),
        ]);
    }

    public function update(Request $request, Tenant $workspace): RedirectResponse
    {
        $data = $this->validateWorkspace($request, false);

        $this->workspaces->update($workspace, $data);

        return redirect()->route('admin.workspaces.index')->with('success', 'Workspace updated.');
    }

    public function toggleStatus(Tenant $workspace): RedirectResponse
    {
        $workspace->update(['status' => $workspace->status === 'suspended' ? 'active' : 'suspended']);

        return back()->with('success', 'Workspace '.($workspace->status === 'suspended' ? 'suspended' : 're-activated').'.');
    }

    public function destroy(Tenant $workspace): RedirectResponse
    {
        $workspace->delete(); // cascades to users, devices, contacts, campaigns…

        return redirect()->route('admin.workspaces.index')->with('success', 'Workspace deleted.');
    }

    /**
     * @return array<string, mixed>
     */
    private function validateWorkspace(Request $request, bool $creating): array
    {
        return $request->validate([
            'name'        => ['required', 'string', 'max:255'],
            'owner_name'  => [Rule::requiredIf($creating), 'nullable', 'string', 'max:255'],
            'owner_email' => [Rule::requiredIf($creating), 'nullable', 'email', 'max:255', Rule::unique('users', 'email')],
            'password'    => [Rule::requiredIf($creating), 'nullable', 'string', 'min:8'],
            'plan_type'   => ['nullable', Rule::in(array_keys(WorkspaceService::PLAN_TYPES))],
            'plan'        => ['nullable', Rule::exists('plans', 'key')],
            'max_devices' => ['required', 'integer', 'min:0', 'max:100000'],
            'modules'     => ['array'],
            'modules.*'   => [Rule::in(array_keys(config('modules')))],
        ]);
    }
}
