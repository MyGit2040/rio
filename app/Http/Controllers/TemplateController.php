<?php

namespace App\Http\Controllers;

use App\Http\Requests\TemplateRequest;
use App\Models\Template;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;

class TemplateController extends Controller
{
    public function index(): View
    {
        $templates = Template::latest()->paginate(20);

        return view('templates.index', compact('templates'));
    }

    public function create(): View
    {
        return view('templates.create', ['template' => new Template(['type' => 'text'])]);
    }

    public function store(TemplateRequest $request): RedirectResponse
    {
        Template::create($request->toTemplate());

        return redirect()->route('templates.index')->with('success', 'Template created.');
    }

    public function edit(Template $template): View
    {
        return view('templates.edit', compact('template'));
    }

    public function update(TemplateRequest $request, Template $template): RedirectResponse
    {
        $template->update($request->toTemplate());

        return redirect()->route('templates.index')->with('success', 'Template updated.');
    }

    public function destroy(Template $template): RedirectResponse
    {
        $template->delete();

        return redirect()->route('templates.index')->with('success', 'Template deleted.');
    }

    /**
     * One-click: rewrite a base message into N distinct variations using AI.
     */
    public function variants(Request $request): JsonResponse
    {
        $data = $request->validate([
            'message' => ['required', 'string', 'max:2000'],
            'count'   => ['required', 'integer', 'min:1', 'max:20'],
        ]);

        $key = config('services.openai.key');
        if (! $key) {
            return response()->json(['error' => 'AI is not configured. Add OPENAI_API_KEY to enable one-click variants.'], 422);
        }

        try {
            $response = Http::withToken($key)->timeout(45)->post('https://api.openai.com/v1/chat/completions', [
                'model'       => config('services.openai.model', 'gpt-4o-mini'),
                'temperature' => 0.9,
                'messages'    => [
                    ['role' => 'system', 'content' => 'You rewrite a marketing WhatsApp message into distinct variations with the same meaning and tone but different wording. Keep any {{name}}, {{phone}} or {{date}} placeholders intact. Return ONLY a JSON array of strings.'],
                    ['role' => 'user', 'content' => "Create {$data['count']} variations of this message:\n\n{$data['message']}"],
                ],
            ]);

            if (! $response->successful()) {
                return response()->json(['error' => 'The AI request failed. Try again.'], 422);
            }

            $variants = $this->parseVariants((string) data_get($response->json(), 'choices.0.message.content', ''), $data['count']);

            return response()->json(['variants' => $variants]);
        } catch (\Throwable $e) {
            Log::error('AI variant generation failed', ['error' => $e->getMessage()]);

            return response()->json(['error' => 'Could not generate variants.'], 422);
        }
    }

    /**
     * @return array<int, string>
     */
    private function parseVariants(string $content, int $count): array
    {
        $content = trim(preg_replace('/```[a-z]*|```/i', '', $content));

        $json = json_decode($content, true);
        if (is_array($json)) {
            return array_slice(array_values(array_filter(array_map(fn ($v) => trim((string) $v), $json))), 0, $count);
        }

        // Fallback: split lines and strip any list numbering/bullets.
        $lines = collect(preg_split('/\n+/', $content))
            ->map(fn ($l) => trim(preg_replace('/^\s*[\d\-\*\.\)]+\s*/', '', $l)))
            ->filter()
            ->values()
            ->all();

        return array_slice($lines, 0, $count);
    }
}

