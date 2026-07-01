<?php

namespace App\Http\Controllers;

use App\Http\Requests\TemplateRequest;
use App\Models\Template;
use App\Services\AiService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class TemplateController extends Controller
{
    public function index(Request $request): View
    {
        $templates = Template::query()
            ->when($request->filled('q'), fn ($query) => $query->where(fn ($w) =>
                $w->where('name', 'like', '%'.$request->input('q').'%')
                  ->orWhere('body', 'like', '%'.$request->input('q').'%')))
            ->when($request->filled('type'), fn ($query) => $query->where('type', $request->input('type')))
            ->latest()
            ->paginate(20)
            ->withQueryString();

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

    /**
     * Read-only WhatsApp-style preview of a saved template.
     */
    public function preview(Template $template): View
    {
        return view('templates.preview', compact('template'));
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

    public function bulk(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'action' => ['required', 'in:delete'],
            'ids'    => ['required', 'array', 'min:1'],
            'ids.*'  => ['integer'],
        ]);

        $count = Template::whereIn('id', $data['ids'])->count();
        Template::whereIn('id', $data['ids'])->delete();

        return back()->with('success', "{$count} template(s) deleted.");
    }

    public function clone(Template $template): RedirectResponse
    {
        $copy = $template->replicate();
        $copy->name = $template->name.' (copy)';
        $copy->save();

        return redirect()->route('templates.edit', $copy)->with('success', 'Template duplicated — edit your copy.');
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

        $ai = AiService::forTenant(auth()->user()->tenant);
        if (! $ai->configured()) {
            return response()->json(['error' => 'Add an AI key (ChatGPT, Gemini or Claude) in Settings to use one-click variants.'], 422);
        }

        $content = $ai->generate(
            'You rewrite a marketing WhatsApp message into distinct variations with the same meaning and tone but different wording. Keep any {{name}}, {{phone}} or {{date}} placeholders intact. Return ONLY a JSON array of strings.',
            "Create {$data['count']} variations of this message:\n\n{$data['message']}",
        );

        if (! $content) {
            return response()->json(['error' => 'The AI request failed. Check your key in Settings and try again.'], 422);
        }

        return response()->json(['variants' => $this->parseVariants($content, $data['count'])]);
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

