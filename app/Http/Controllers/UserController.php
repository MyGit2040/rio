<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;

class UserController extends Controller
{
    /**
     * Only the workspace owner may manage team members.
     */
    private function guard(): void
    {
        abort_unless(auth()->user()->isOwner(), 403);
    }

    /**
     * Ensure a user belongs to the current tenant (route-model binding is not scoped for User).
     */
    private function sameTenant(User $user): void
    {
        abort_unless($user->tenant_id === auth()->user()->tenant_id, 404);
    }

    public function index(Request $request): View
    {
        $this->guard();

        $users = User::where('tenant_id', auth()->user()->tenant_id)
            ->when($request->filled('q'), fn ($query) => $query->where(fn ($w) =>
                $w->where('name', 'like', '%'.$request->input('q').'%')
                  ->orWhere('email', 'like', '%'.$request->input('q').'%')))
            ->when($request->filled('role'), fn ($query) => $query->where('role', $request->input('role')))
            ->orderByDesc('role')->orderBy('name')->get();

        return view('users.index', compact('users'));
    }

    public function create(): View
    {
        $this->guard();

        return view('users.create');
    }

    public function store(Request $request): RedirectResponse
    {
        $this->guard();

        $data = $request->validate([
            'name'     => ['required', 'string', 'max:255'],
            'email'    => ['required', 'email', 'max:255', 'unique:users,email'],
            'role'     => ['required', Rule::in(['owner', 'member'])],
            'password' => ['required', 'confirmed', Password::defaults()],
        ]);

        User::create([
            'tenant_id' => auth()->user()->tenant_id,
            'name'      => $data['name'],
            'email'     => $data['email'],
            'role'      => $data['role'],
            'password'  => Hash::make($data['password']),
        ]);

        return redirect()->route('users.index')->with('success', 'Team member added.');
    }

    public function edit(User $user): View
    {
        $this->guard();
        $this->sameTenant($user);

        return view('users.edit', compact('user'));
    }

    public function update(Request $request, User $user): RedirectResponse
    {
        $this->guard();
        $this->sameTenant($user);

        $data = $request->validate([
            'name'     => ['required', 'string', 'max:255'],
            'email'    => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
            'role'     => ['required', Rule::in(['owner', 'member'])],
            'password' => ['nullable', 'confirmed', Password::defaults()],
        ]);

        // Never demote the last remaining owner.
        if ($user->isOwner() && $data['role'] !== 'owner' && $this->ownerCount() <= 1) {
            return back()->with('error', 'You must keep at least one owner.');
        }

        $user->fill(['name' => $data['name'], 'email' => $data['email'], 'role' => $data['role']]);
        if (! empty($data['password'])) {
            $user->password = Hash::make($data['password']);
        }
        $user->save();

        return redirect()->route('users.index')->with('success', 'Team member updated.');
    }

    public function destroy(User $user): RedirectResponse
    {
        $this->guard();
        $this->sameTenant($user);

        if ($user->id === auth()->id()) {
            return back()->with('error', "You can't remove yourself.");
        }
        if ($user->isOwner() && $this->ownerCount() <= 1) {
            return back()->with('error', 'You must keep at least one owner.');
        }

        $user->delete();

        return redirect()->route('users.index')->with('success', 'Team member removed.');
    }

    private function ownerCount(): int
    {
        return User::where('tenant_id', auth()->user()->tenant_id)->where('role', 'owner')->count();
    }
}
