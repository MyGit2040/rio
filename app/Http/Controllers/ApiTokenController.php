<?php

namespace App\Http\Controllers;

use App\Models\ApiToken;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ApiTokenController extends Controller
{
    private function guard(): void
    {
        abort_unless(auth()->user()->isOwner(), 403);
    }

    public function index(): View
    {
        $this->guard();

        return view('api-tokens.index', ['tokens' => ApiToken::latest()->get()]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->guard();

        $data = $request->validate(['name' => ['required', 'string', 'max:255']]);

        [, $plain] = ApiToken::generate($data['name']);

        return back()
            ->with('success', 'Token created — copy it now, it will not be shown again.')
            ->with('plain_token', $plain);
    }

    public function destroy(ApiToken $token): RedirectResponse
    {
        $this->guard();
        abort_unless($token->tenant_id === auth()->user()->tenant_id, 404);

        $token->delete();

        return back()->with('success', 'Token revoked.');
    }
}
