<?php

namespace App\Http\Controllers;

use App\Models\ContactGroup;
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
}
