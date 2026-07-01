<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\PlanRequest;
use App\Models\Plan;
use App\Models\Tenant;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class PlanController extends Controller
{
    public function index(): View
    {
        $plans = Plan::ordered()->get();

        // How many workspaces are on each plan (tenant.plan stores the key).
        $counts = Tenant::selectRaw('plan, COUNT(*) as c')->groupBy('plan')->pluck('c', 'plan');

        return view('admin.plans.index', compact('plans', 'counts'));
    }

    public function create(): View
    {
        $plan = new Plan([
            'billing_period' => 'monthly',
            'is_active'      => true,
            'limits'         => ['devices' => 1, 'contacts' => 500, 'monthly_messages' => 1000],
        ]);

        return view('admin.plans.create', compact('plan'));
    }

    public function store(PlanRequest $request): RedirectResponse
    {
        $plan = Plan::create($request->toPlan());
        $this->syncSingleDefault($plan);

        return redirect()->route('admin.plans.index')->with('success', "Plan \"{$plan->name}\" created.");
    }

    public function edit(Plan $plan): View
    {
        return view('admin.plans.edit', compact('plan'));
    }

    public function update(PlanRequest $request, Plan $plan): RedirectResponse
    {
        $plan->update($request->toPlan());
        $this->syncSingleDefault($plan);

        return redirect()->route('admin.plans.index')->with('success', 'Plan updated.');
    }

    public function destroy(Plan $plan): RedirectResponse
    {
        if (Tenant::where('plan', $plan->key)->exists()) {
            return back()->with('error', 'Cannot delete — workspaces are still on this plan. Move them to another plan first.');
        }

        $plan->delete();

        return redirect()->route('admin.plans.index')->with('success', 'Plan deleted.');
    }

    /** Only one plan may be the default — clear the flag on the others. */
    private function syncSingleDefault(Plan $plan): void
    {
        if ($plan->is_default) {
            Plan::where('id', '!=', $plan->id)->where('is_default', true)->update(['is_default' => false]);
        }
    }
}
