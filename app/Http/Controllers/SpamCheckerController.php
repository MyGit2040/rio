<?php

namespace App\Http\Controllers;

use App\Services\SpamScoreService;
use Illuminate\Http\Request;
use Illuminate\View\View;

class SpamCheckerController extends Controller
{
    public function index(): View
    {
        return view('spam-checker.index', ['result' => null, 'message' => '']);
    }

    public function check(Request $request, SpamScoreService $service): View
    {
        $data = $request->validate([
            'message' => ['required', 'string', 'max:8000'],
        ]);

        return view('spam-checker.index', [
            'message' => $data['message'],
            'result'  => $service->analyze($data['message']),
        ]);
    }
}
