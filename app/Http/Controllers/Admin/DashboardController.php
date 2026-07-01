<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WhatsappInstance;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function index(): View
    {
        $tenants = Tenant::all();

        $stats = [
            'workspaces' => $tenants->count(),
            'active'     => $tenants->filter(fn ($t) => ! $t->isBlocked())->count(),
            'blocked'    => $tenants->filter(fn ($t) => $t->isBlocked())->count(),
            'devices'    => WhatsappInstance::withoutGlobalScopes()->count(),
            'users'      => User::where('is_super_admin', false)->count(),
        ];

        $recent = Tenant::withCount('users')->latest()->take(8)->get();

        return view('admin.dashboard', compact('stats', 'recent'));
    }
}
