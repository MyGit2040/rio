<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Contact;
use App\Support\Tenancy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ContactController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $contacts = Contact::query()
            ->search($request->input('q'))
            ->latest()
            ->paginate(min(100, (int) $request->input('per_page', 50)));

        return response()->json($contacts);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name'    => ['nullable', 'string', 'max:255'],
            'phone'   => [
                'required', 'string', 'max:32',
                Rule::unique('contacts', 'phone')->where('tenant_id', Tenancy::id()),
            ],
            'email'   => ['nullable', 'email', 'max:255'],
            'country' => ['nullable', 'string', 'max:64'],
        ]);

        $data['phone'] = preg_replace('/\D+/', '', $data['phone']);
        $contact = Contact::create($data);

        return response()->json($contact, 201);
    }
}
