<?php

use App\Http\Controllers\Api\ContactController;
use App\Http\Controllers\Api\MessageController;
use App\Http\Controllers\Api\ResourceController;
use App\Http\Controllers\Api\TemplateController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
 | Eagle REST API — authenticate with a workspace token:
 |   Authorization: Bearer eag_xxxxxxxx
 | Create tokens in the app under Settings → API.
 */

Route::middleware('api.token')->group(function () {
    Route::get('/me', fn (Request $request) => response()->json([
        'tenant_id' => $request->attributes->get('tenant_id'),
    ]));

    Route::get('/contacts', [ContactController::class, 'index']);
    Route::post('/contacts', [ContactController::class, 'store']);

    Route::get('/devices', [ResourceController::class, 'devices']);
    Route::get('/campaigns', [ResourceController::class, 'campaigns']);

    Route::get('/templates', [TemplateController::class, 'index']);
    Route::post('/templates/send', [TemplateController::class, 'send']);

    Route::post('/messages', [MessageController::class, 'send']);
});
