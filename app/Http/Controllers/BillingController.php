<?php

namespace App\Http\Controllers;

use App\Services\PlanLimit;
use App\Support\Audit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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
            'tenant'   => $tenant,
            'current'  => $limits->planKey(),
            'tiers'    => config('plans.tiers'),
            'usage'    => $usage,
        ]);
    }

    /**
     * Switch plan (payment handled off-platform for now — instant switch).
     */
    public function update(Request $request): RedirectResponse
    {
        abort_unless(auth()->user()->isOwner(), 403);

        $data = $request->validate([
            'plan' => ['required', 'in:'.implode(',', array_keys(config('plans.tiers')))],
        ]);

        $tenant = auth()->user()->tenant;
        $tenant->update(['plan' => $data['plan']]);

        Audit::log('billing.plan_changed', $tenant, 'Switched to '.$data['plan']);

        return back()->with('success', 'Your plan is now '.config("plans.tiers.{$data['plan']}.name").'.');
    }
}
