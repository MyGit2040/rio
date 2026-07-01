<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AuditController extends Controller
{
    public function index(Request $request): View
    {
        abort_unless(auth()->user()->isOwner(), 403);

        $logs = ActivityLog::with('user')
            ->when($request->filled('action'), fn ($q) => $q->where('action', $request->input('action')))
            ->when($request->filled('q'), fn ($q) => $q->where('description', 'like', '%'.$request->input('q').'%'))
            ->when($request->filled('created_from'), fn ($q) => $q->whereDate('created_at', '>=', $request->input('created_from')))
            ->when($request->filled('created_to'), fn ($q) => $q->whereDate('created_at', '<=', $request->input('created_to')))
            ->orderByDesc('id')
            ->paginate(40)
            ->withQueryString();

        $actions = ActivityLog::query()->distinct()->orderBy('action')->pluck('action');

        return view('audit.index', compact('logs', 'actions'));
    }
}
