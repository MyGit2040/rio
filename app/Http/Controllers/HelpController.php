<?php

namespace App\Http\Controllers;

use App\Services\AiService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class HelpController extends Controller
{
    public function index(Request $request): View
    {
        $q = trim((string) $request->input('q'));
        $articles = collect(config('help'));

        if ($q !== '') {
            $needle = mb_strtolower($q);
            $articles = $articles->filter(function ($a) use ($needle) {
                $haystack = mb_strtolower($a['title'].' '.$a['summary'].' '.implode(' ', $a['steps'] ?? []).' '.($a['example'] ?? ''));

                return str_contains($haystack, $needle);
            });
        }

        return view('help.index', [
            'articles' => $articles,
            'q'        => $q,
            'aiReady'  => AiService::forTenant(auth()->user()->tenant)->configured(),
        ]);
    }

    public function show(string $article): View
    {
        $data = config("help.$article");
        abort_if(! $data, 404);

        return view('help.show', [
            'key'     => $article,
            'article' => $data,
            'related' => collect(config('help'))->except($article)->take(4),
        ]);
    }

    /**
     * AI helper — answers a question about Eagle using the workspace's AI key.
     */
    public function ask(Request $request): JsonResponse
    {
        $question = trim((string) $request->validate(['question' => ['required', 'string', 'max:500']])['question']);

        $ai = AiService::forTenant(auth()->user()->tenant);
        if (! $ai->configured()) {
            return response()->json(['ok' => false, 'message' => 'Add an AI key in Settings to use the AI helper.'], 422);
        }

        // Ground the model in what Eagle actually does (from the help content).
        $context = collect(config('help'))
            ->map(fn ($a) => '- '.$a['title'].': '.$a['summary'])
            ->implode("\n");

        $answer = $ai->generate(
            "You are the in-app help assistant for Eagle, a WhatsApp marketing tool. "
            ."Answer in simple English with short bullet points. Only use Eagle's real features listed below; "
            ."if unsure, say so and point to the Help Center. Features:\n".$context,
            $question,
        );

        return $answer !== null
            ? response()->json(['ok' => true, 'answer' => $answer])
            : response()->json(['ok' => false, 'message' => 'The AI request failed. Check your key in Settings.'], 422);
    }
}
