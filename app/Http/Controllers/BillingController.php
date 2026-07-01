<?php

namespace App\Http\Controllers;

use App\Models\Plan;
use App\Services\PlanLimit;
use App\Support\Audit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class BillingController extends Controller
{
    public function index(): View
    {
        abort_unless(auth()->user()->isOwner(), 403);

        $tenant = auth()->user()->tenant;
        $limits = PlanLimit::for($tenant);

        $usage = collect(['devices', 'contacts', 'monthly_messages'])->mapWithKeys(fn ($key) => [$key => [
            'used'      => $limits->usage($key),
            'limit'     => $limits->limit($key),
            'percent'   => $limits->percent($key),
            'remaining' => $limits->remaining($key),
        ]])->all();

        return view('billing.index', [
            'tenant'  => $tenant,
            'current' => $limits->planKey(),
            'plans'   => Plan::active()->ordered()->get(),
            'usage'   => $usage,
        ]);
    }

    /**
     * Switch plan (payment handled off-platform for now — instant switch).
     */
    public function update(Request $request): RedirectResponse
    {
        abort_unless(auth()->user()->isOwner(), 403);

        $data = $request->validate([
            'plan' => ['required', Rule::exists('plans', 'key')->where('is_active', true)],
        ]);

        $tenant = auth()->user()->tenant;
        $tenant->update(['plan' => $data['plan']]);

        $plan = Plan::byKey($data['plan']);
        Audit::log('billing.plan_changed', $tenant, 'Switched to '.$data['plan']);

        return back()->with('success', 'Your plan is now '.($plan?->name ?? $data['plan']).'.');
    }
}
