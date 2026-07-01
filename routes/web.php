<?php

use App\Http\Controllers\ApiTokenController;
use App\Http\Controllers\BackupController;
use App\Http\Controllers\CampaignController;
use App\Http\Controllers\ChatbotRuleController;
use App\Http\Controllers\ContactController;
use App\Http\Controllers\ContactImportController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DeviceController;
use App\Http\Controllers\GroupController;
use App\Http\Controllers\InvoiceController;
use App\Http\Controllers\OrderWebhookController;
use App\Http\Controllers\Auth\TwoFactorController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\SecurityController;
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\SpamCheckerController;
use App\Http\Controllers\TemplateController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\WebhookController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return auth()->check()
        ? redirect()->route('dashboard')
        : redirect()->route('login');
});

// Two-factor challenge (after password, before full login — guest-accessible).
Route::get('/two-factor-challenge', [TwoFactorController::class, 'show'])->name('two-factor.show');
Route::post('/two-factor-challenge', [TwoFactorController::class, 'store'])->name('two-factor.store');
Route::post('/two-factor-resend', [TwoFactorController::class, 'resend'])->name('two-factor.resend');

Route::middleware('auth')->group(function () {
    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');

    // Account security (2FA)
    Route::get('/security', [SecurityController::class, 'edit'])->name('security.edit');
    Route::post('/security/totp/setup', [SecurityController::class, 'setupTotp'])->name('security.totp.setup');
    Route::post('/security/totp/enable', [SecurityController::class, 'enableTotp'])->name('security.totp.enable');
    Route::post('/security/email/enable', [SecurityController::class, 'enableEmail'])->name('security.email.enable');
    Route::post('/security/disable', [SecurityController::class, 'disable'])->name('security.disable');

    // Devices (WhatsApp numbers)
    Route::get('/devices', [DeviceController::class, 'index'])->name('devices.index');
    Route::post('/devices', [DeviceController::class, 'store'])->name('devices.store');
    Route::get('/devices/{device}', [DeviceController::class, 'show'])->name('devices.show');
    Route::patch('/devices/{device}', [DeviceController::class, 'update'])->name('devices.update');
    Route::post('/devices/{device}/privacy', [DeviceController::class, 'updatePrivacy'])->name('devices.privacy');
    Route::get('/devices/{device}/state', [DeviceController::class, 'state'])->name('devices.state');
    Route::post('/devices/{device}/connect', [DeviceController::class, 'connect'])->name('devices.connect');
    Route::delete('/devices/{device}', [DeviceController::class, 'destroy'])->name('devices.destroy');

    // Contacts
    Route::get('/contacts/import', [ContactImportController::class, 'create'])->name('contacts.import.create');
    Route::post('/contacts/import', [ContactImportController::class, 'store'])->name('contacts.import.store');
    Route::post('/contacts/verify', [ContactController::class, 'verify'])->name('contacts.verify');
    Route::resource('contacts', ContactController::class)->except('show');

    // Contact groups
    Route::resource('groups', GroupController::class)->except('show');

    // Message templates (text / media / poll)
    Route::post('/templates/variants', [TemplateController::class, 'variants'])->name('templates.variants');
    Route::resource('templates', TemplateController::class)->except('show');

    // Campaigns (bulk send)
    Route::resource('campaigns', CampaignController::class)->except('edit', 'update');
    Route::post('/campaigns/{campaign}/launch', [CampaignController::class, 'launch'])->name('campaigns.launch');
    Route::post('/campaigns/{campaign}/pause', [CampaignController::class, 'pause'])->name('campaigns.pause');
    Route::get('/campaigns/{campaign}/progress', [CampaignController::class, 'progress'])->name('campaigns.progress');

    // Chatbot rules
    Route::resource('chatbot', ChatbotRuleController::class)->parameters(['chatbot' => 'rule'])->except('show');

    // Spam-score checker (content quality aid)
    Route::get('/spam-checker', [SpamCheckerController::class, 'index'])->name('spam.index');
    Route::post('/spam-checker', [SpamCheckerController::class, 'check'])->name('spam.check');

    // Team / users (SaaS — owner-managed)
    Route::resource('users', UserController::class)->except('show');

    // Orders / invoices (from WhatsApp shop)
    Route::get('/invoices', [InvoiceController::class, 'index'])->name('invoices.index');
    Route::patch('/invoices/{invoice}', [InvoiceController::class, 'updateStatus'])->name('invoices.status');

    // REST API tokens
    Route::get('/api-tokens', [ApiTokenController::class, 'index'])->name('api-tokens.index');
    Route::post('/api-tokens', [ApiTokenController::class, 'store'])->name('api-tokens.store');
    Route::delete('/api-tokens/{token}', [ApiTokenController::class, 'destroy'])->name('api-tokens.destroy');

    // Backup & restore
    Route::get('/backup', [BackupController::class, 'index'])->name('backup.index');
    Route::post('/backup/create', [BackupController::class, 'create'])->name('backup.create');
    Route::post('/backup/restore', [BackupController::class, 'restore'])->name('backup.restore');

    // Settings (Evolution connection + AI)
    Route::get('/settings', [SettingsController::class, 'edit'])->name('settings.edit');
    Route::put('/settings', [SettingsController::class, 'update'])->name('settings.update');

    // Profile (Breeze)
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

// Inbound webhook from the Evolution engine (no auth — verified by secret).
Route::post('/webhooks/evolution/{secret?}', [WebhookController::class, 'handle'])->name('webhooks.evolution');

// Inbound WhatsApp shop order webhook (no auth — verified by secret).
Route::post('/webhooks/order/{secret?}', [OrderWebhookController::class, 'handle'])->name('webhooks.order');

require __DIR__.'/auth.php';
