<?php

use App\Http\Controllers\Admin\DashboardController as AdminDashboardController;
use App\Http\Controllers\Admin\WorkspaceController;
use App\Http\Controllers\ApiTokenController;
use App\Http\Controllers\AuditController;
use App\Http\Controllers\BackupController;
use App\Http\Controllers\BillingController;
use App\Http\Controllers\CampaignController;
use App\Http\Controllers\ChatbotRuleController;
use App\Http\Controllers\ContactController;
use App\Http\Controllers\ContactImportController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DeviceController;
use App\Http\Controllers\GroupController;
use App\Http\Controllers\HealthController;
use App\Http\Controllers\HelpController;
use App\Http\Controllers\InboxController;
use App\Http\Controllers\InvoiceController;
use App\Http\Controllers\LinkController;
use App\Http\Controllers\MediaController;
use App\Http\Controllers\OrderWebhookController;
use App\Http\Controllers\Auth\TwoFactorController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\SecurityController;
use App\Http\Controllers\SequenceController;
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\SingleMessageController;
use App\Http\Controllers\SpamCheckerController;
use App\Http\Controllers\SubscriptionController;
use App\Http\Controllers\SuppressionController;
use App\Http\Controllers\TemplateController;
use App\Http\Controllers\UploadController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\WebhookController;
use App\Http\Controllers\WebhookEndpointController;
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

    // Subscription blocked notice (suspended / expired workspaces).
    Route::get('/subscription/inactive', [SubscriptionController::class, 'inactive'])->name('subscription.inactive');

    // Platform admin (super-admin only) — manage client workspaces & subscriptions.
    Route::prefix('admin')->name('admin.')->middleware('superadmin')->group(function () {
        Route::get('/', [AdminDashboardController::class, 'index'])->name('dashboard');
        Route::get('/workspaces', [WorkspaceController::class, 'index'])->name('workspaces.index');
        Route::get('/workspaces/create', [WorkspaceController::class, 'create'])->name('workspaces.create');
        Route::post('/workspaces', [WorkspaceController::class, 'store'])->name('workspaces.store');
        Route::get('/workspaces/{workspace}/edit', [WorkspaceController::class, 'edit'])->name('workspaces.edit');
        Route::put('/workspaces/{workspace}', [WorkspaceController::class, 'update'])->name('workspaces.update');
        Route::post('/workspaces/{workspace}/status', [WorkspaceController::class, 'toggleStatus'])->name('workspaces.status');
        Route::delete('/workspaces/{workspace}', [WorkspaceController::class, 'destroy'])->name('workspaces.destroy');
    });

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
    Route::get('/contacts/import/sample', [ContactImportController::class, 'sample'])->name('contacts.import.sample');
    Route::get('/contacts/import', [ContactImportController::class, 'create'])->name('contacts.import.create');
    Route::post('/contacts/import', [ContactImportController::class, 'store'])->name('contacts.import.store');
    Route::get('/contacts/export', [ContactController::class, 'export'])->name('contacts.export');
    Route::post('/contacts/bulk', [ContactController::class, 'bulk'])->name('contacts.bulk');
    Route::post('/contacts/verify', [ContactController::class, 'verify'])->name('contacts.verify');
    Route::resource('contacts', ContactController::class)->except('show');

    // Contact groups
    Route::post('/groups/bulk', [GroupController::class, 'bulk'])->name('groups.bulk');
    Route::resource('groups', GroupController::class)->except('show');
    Route::get('/groups/{group}', [GroupController::class, 'show'])->name('groups.show');
    Route::post('/groups/{group}/import', [GroupController::class, 'import'])->name('groups.import');
    Route::post('/groups/{group}/verify', [GroupController::class, 'verify'])->name('groups.verify');
    Route::get('/groups/{group}/progress', [GroupController::class, 'progress'])->name('groups.progress');
    Route::post('/groups/{group}/reverify', [GroupController::class, 'reverify'])->name('groups.reverify');
    Route::delete('/groups/{group}/invalid', [GroupController::class, 'deleteInvalid'])->name('groups.delete-invalid');
    Route::delete('/groups/{group}/contacts/{contact}', [GroupController::class, 'removeContact'])->name('groups.remove-contact');

    // Contact profile / timeline
    Route::get('/contacts/{contact}', [ContactController::class, 'show'])->name('contacts.show');

    // Suppression (do-not-contact) list
    Route::get('/suppressions', [SuppressionController::class, 'index'])->name('suppressions.index');
    Route::post('/suppressions', [SuppressionController::class, 'store'])->name('suppressions.store');
    Route::post('/suppressions/import', [SuppressionController::class, 'import'])->name('suppressions.import');
    Route::post('/suppressions/bulk', [SuppressionController::class, 'bulk'])->name('suppressions.bulk');
    Route::delete('/suppressions/{suppression}', [SuppressionController::class, 'destroy'])->name('suppressions.destroy');

    // Two-way inbox
    Route::get('/inbox', [InboxController::class, 'index'])->name('inbox.index');
    Route::get('/inbox/{contact}', [InboxController::class, 'show'])->name('inbox.show');
    Route::post('/inbox/{contact}/reply', [InboxController::class, 'reply'])->name('inbox.reply');

    // Drip / follow-up sequences
    Route::post('/sequences/bulk', [SequenceController::class, 'bulk'])->name('sequences.bulk');
    Route::resource('sequences', SequenceController::class);
    Route::post('/sequences/{sequence}/enroll', [SequenceController::class, 'enroll'])->name('sequences.enroll');

    // Number-health dashboard
    Route::get('/health', [HealthController::class, 'index'])->name('health.index');

    // Media library
    Route::get('/media', [MediaController::class, 'index'])->name('media.index');
    Route::post('/media', [MediaController::class, 'store'])->name('media.store');
    Route::post('/media/bulk', [MediaController::class, 'bulk'])->name('media.bulk');
    Route::delete('/media/{asset}', [MediaController::class, 'destroy'])->name('media.destroy');

    // Reports (performance + link clicks)
    Route::get('/reports', [ReportController::class, 'index'])->name('reports.index');

    // Outbound webhook endpoints
    Route::get('/webhook-endpoints', [WebhookEndpointController::class, 'index'])->name('webhook-endpoints.index');
    Route::post('/webhook-endpoints', [WebhookEndpointController::class, 'store'])->name('webhook-endpoints.store');
    Route::delete('/webhook-endpoints/{webhook}', [WebhookEndpointController::class, 'destroy'])->name('webhook-endpoints.destroy');

    // Billing & plans
    Route::get('/billing', [BillingController::class, 'index'])->name('billing.index');
    Route::put('/billing', [BillingController::class, 'update'])->name('billing.update');

    // Audit log
    Route::get('/audit', [AuditController::class, 'index'])->name('audit.index');

    // Help center
    Route::get('/help', [HelpController::class, 'index'])->name('help.index');
    Route::post('/help/ask', [HelpController::class, 'ask'])->name('help.ask');
    Route::get('/help/{article}', [HelpController::class, 'show'])->name('help.show');

    // Shared attachment upload (returns a public URL)
    Route::post('/uploads', [UploadController::class, 'store'])->name('uploads.store');

    // Message templates (text / media / poll)
    Route::post('/templates/variants', [TemplateController::class, 'variants'])->name('templates.variants');
    Route::post('/templates/bulk', [TemplateController::class, 'bulk'])->name('templates.bulk');
    Route::resource('templates', TemplateController::class)->except('show');
    Route::get('/templates/{template}/preview', [TemplateController::class, 'preview'])->name('templates.preview');
    Route::post('/templates/{template}/clone', [TemplateController::class, 'clone'])->name('templates.clone');

    // Single message (one-off send)
    Route::get('/single-message', [SingleMessageController::class, 'create'])->name('single-message.create');
    Route::post('/single-message', [SingleMessageController::class, 'send'])->name('single-message.send');

    // Campaigns (bulk send)
    Route::post('/campaigns/bulk', [CampaignController::class, 'bulk'])->name('campaigns.bulk');
    Route::resource('campaigns', CampaignController::class)->except('edit', 'update');
    Route::post('/campaigns/{campaign}/launch', [CampaignController::class, 'launch'])->name('campaigns.launch');
    Route::post('/campaigns/{campaign}/pause', [CampaignController::class, 'pause'])->name('campaigns.pause');
    Route::post('/campaigns/{campaign}/retry', [CampaignController::class, 'retryFailed'])->name('campaigns.retry');
    Route::post('/campaigns/{campaign}/test', [CampaignController::class, 'test'])->name('campaigns.test');
    Route::get('/campaigns/{campaign}/export', [CampaignController::class, 'export'])->name('campaigns.export');
    Route::get('/campaigns/{campaign}/progress', [CampaignController::class, 'progress'])->name('campaigns.progress');
    Route::get('/campaigns/{campaign}/responses', [CampaignController::class, 'responses'])->name('campaigns.responses');

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
    Route::post('/settings/test-email', [SettingsController::class, 'testEmail'])->name('settings.test-email');
    Route::post('/settings/test-ai', [SettingsController::class, 'testAi'])->name('settings.test-ai');

    // Profile (Breeze)
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

// Inbound webhook from the Evolution engine (no auth — verified by secret).
Route::post('/webhooks/evolution/{secret?}', [WebhookController::class, 'handle'])->name('webhooks.evolution');

// Inbound WhatsApp shop order webhook (no auth — verified by secret).
Route::post('/webhooks/order/{secret?}', [OrderWebhookController::class, 'handle'])->name('webhooks.order');

// Public tracked-link redirect (records a click, then forwards on).
Route::get('/l/{token}', [LinkController::class, 'click'])->name('links.click');

require __DIR__.'/auth.php';
