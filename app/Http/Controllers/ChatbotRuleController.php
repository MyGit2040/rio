<?php

namespace App\Http\Controllers;

use App\Models\ChatbotRule;
use App\Models\WhatsappInstance;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ChatbotRuleController extends Controller
{
    public function index(): View
    {
        $rules = ChatbotRule::with('instance')->orderBy('priority')->get();

        return view('chatbot.index', [
            'rules'     => $rules,
            'aiEnabled' => (bool) data_get(auth()->user()->tenant->settings, 'ai_enabled', false),
        ]);
    }

    public function create(): View
    {
        return view('chatbot.create', [
            'rule'    => new ChatbotRule(['match_type' => 'contains', 'is_active' => true, 'priority' => 100]),
            'devices' => WhatsappInstance::orderBy('name')->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        ChatbotRule::create($this->validated($request));

        return redirect()->route('chatbot.index')->with('success', 'Chatbot rule created.');
    }

    public function edit(ChatbotRule $rule): View
    {
        return view('chatbot.edit', [
            'rule'    => $rule,
            'devices' => WhatsappInstance::orderBy('name')->get(),
        ]);
    }

    public function update(Request $request, ChatbotRule $rule): RedirectResponse
    {
        $rule->update($this->validated($request));

        return redirect()->route('chatbot.index')->with('success', 'Chatbot rule updated.');
    }

    public function destroy(ChatbotRule $rule): RedirectResponse
    {
        $rule->delete();

        return redirect()->route('chatbot.index')->with('success', 'Chatbot rule deleted.');
    }

    private function validated(Request $request): array
    {
        $data = $request->validate([
            'name'                 => ['required', 'string', 'max:255'],
            'whatsapp_instance_id' => ['nullable', 'integer', 'exists:whatsapp_instances,id'],
            'match_type'           => ['required', 'in:contains,exact,starts_with,any,ai'],
            'keywords'             => ['nullable', 'string', 'max:500'],
            'reply'                => ['nullable', 'string', 'max:4096', 'required_unless:match_type,ai'],
            'use_ai'               => ['sometimes', 'boolean'],
            'is_active'            => ['sometimes', 'boolean'],
            'priority'             => ['required', 'integer', 'min:1', 'max:9999'],
        ]);

        $data['use_ai'] = $request->boolean('use_ai') || $data['match_type'] === 'ai';
        $data['is_active'] = $request->boolean('is_active');

        return $data;
    }
}
