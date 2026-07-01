<?php

namespace Tests\Feature;

use App\Jobs\DispatchWebhook;
use App\Jobs\SendCampaignMessage;
use App\Jobs\SendTransactionalNotification;
use App\Models\Alert;
use App\Models\ApiToken;
use App\Models\Campaign;
use App\Models\Contact;
use App\Models\ContactGroup;
use App\Models\MediaAsset;
use App\Models\Sequence;
use App\Models\Suppression;
use App\Models\Template;
use App\Models\Tenant;
use App\Models\TrackedLink;
use App\Models\User;
use App\Models\WebhookEndpoint;
use App\Models\WhatsappInstance;
use App\Services\SequenceService;
use App\Services\SpamScoreService;
use App\Support\SendingWindow;
use App\Support\Totp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AppSmokeTest extends TestCase
{
    use RefreshDatabase;

    private function makeUser(string $tenantName = 'Acme', string $email = 'a@test.dev'): User
    {
        $tenant = Tenant::create(['name' => $tenantName, 'slug' => str()->slug($tenantName).'-'.uniqid()]);

        return User::create([
            'tenant_id' => $tenant->id,
            'name'      => 'Owner',
            'email'     => $email,
            'role'      => 'owner',
            'password'  => Hash::make('password'),
        ]);
    }

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get('/dashboard')->assertRedirect('/login');
    }

    public function test_registration_creates_a_tenant(): void
    {
        $this->post('/register', [
            'name' => 'Jane', 'company' => 'Jane Co', 'email' => 'jane@test.dev',
            'password' => 'password', 'password_confirmation' => 'password',
        ])->assertRedirect('/dashboard');

        $this->assertDatabaseHas('tenants', ['name' => 'Jane Co']);
        $this->assertDatabaseHas('users', ['email' => 'jane@test.dev', 'role' => 'owner']);
    }

    public function test_all_main_pages_load(): void
    {
        $user = $this->makeUser();
        $this->actingAs($user);

        $pages = [
            '/dashboard', '/devices', '/contacts', '/contacts/create', '/contacts/import',
            '/groups', '/groups/create', '/templates', '/templates/create',
            '/campaigns', '/campaigns/create', '/chatbot', '/chatbot/create',
            '/spam-checker', '/users', '/users/create', '/invoices',
            '/api-tokens', '/backup', '/security', '/settings',
            // New modules
            '/inbox', '/health', '/sequences', '/sequences/create', '/media',
            '/reports', '/suppressions', '/billing', '/webhook-endpoints', '/audit',
            '/help', '/help/getting-started',
        ];

        foreach ($pages as $page) {
            $this->get($page)->assertOk();
        }
    }

    public function test_contact_can_be_created(): void
    {
        $user = $this->makeUser();
        $this->actingAs($user);

        $this->post('/contacts', ['name' => 'Bob', 'phone' => '+971 50 111 2233'])
            ->assertRedirect('/contacts');

        // Phone is normalised to digits only and scoped to the tenant.
        $this->assertDatabaseHas('contacts', [
            'tenant_id' => $user->tenant_id,
            'phone'     => '971501112233',
        ]);
    }

    public function test_poll_template_can_be_created(): void
    {
        $user = $this->makeUser();
        $this->actingAs($user);

        $this->post('/templates', [
            'name' => 'My Poll', 'type' => 'poll',
            'poll_question' => 'Pick one', 'poll_options' => ['A', 'B', 'C'],
        ])->assertRedirect('/templates');

        $template = Template::withoutGlobalScopes()->where('name', 'My Poll')->first();
        $this->assertSame('poll', $template->type);
        $this->assertSame(['A', 'B', 'C'], $template->poll['options']);
    }

    public function test_tenant_isolation(): void
    {
        $userA = $this->makeUser('A Corp', 'a@corp.dev');
        $userB = $this->makeUser('B Corp', 'b@corp.dev');

        $this->actingAs($userB);
        $contactB = Contact::create(['name' => 'Secret B', 'phone' => '111222333']);

        // User A must not see or reach B's contact.
        $this->actingAs($userA);
        $this->get('/contacts')->assertDontSee('Secret B');
        $this->get("/contacts/{$contactB->id}/edit")->assertNotFound();
        $this->assertNull(Contact::find($contactB->id));
    }

    public function test_send_now_campaign_dispatches_jobs(): void
    {
        Queue::fake();

        $user = $this->makeUser();
        $this->actingAs($user);

        $device = WhatsappInstance::create([
            'name' => 'Line 1', 'instance_name' => 'acme-test', 'status' => 'open',
        ]);
        Contact::create(['name' => 'C1', 'phone' => '971500000001']);
        Contact::create(['name' => 'C2', 'phone' => '971500000002']);

        $this->post('/campaigns', [
            'name' => 'Blast', 'device_ids' => [$device->id],
            'body' => 'Hi {{name}}', 'audience' => 'all',
            'min_delay' => 1, 'max_delay' => 2, 'schedule' => 'now',
        ])->assertRedirect();

        $this->assertDatabaseHas('campaigns', ['name' => 'Blast', 'total' => 2, 'status' => 'sending']);
        $this->assertDatabaseCount('campaign_recipients', 2);
        Queue::assertPushed(SendCampaignMessage::class, 2);
    }

    public function test_transactional_job_sends_via_gateway(): void
    {
        config(['evolution.base_url' => 'http://localhost:8080', 'evolution.api_key' => 'k']);
        Http::fake(['*' => Http::response(['key' => ['id' => 'WAMID-1']], 201)]);

        $user = $this->makeUser();
        $this->actingAs($user);
        $device = WhatsappInstance::create(['name' => 'L', 'instance_name' => 'inst-1', 'status' => 'open']);
        $contact = Contact::create(['name' => 'Bob', 'phone' => '971500000009']);

        (new SendTransactionalNotification($device, $contact, 'Hello'))->handle();

        Http::assertSent(fn ($req) => str_contains($req->url(), '/message/sendText/inst-1')
            && $req->hasHeader('apikey', 'k')
            && $req['number'] === '971500000009');
        $this->assertDatabaseHas('messages', ['phone' => '971500000009', 'direction' => 'out', 'status' => 'sent']);
    }

    public function test_transactional_job_circuit_breaker_on_auth_failure(): void
    {
        config(['evolution.base_url' => 'http://localhost:8080', 'evolution.api_key' => 'k']);
        Http::fake(['*' => Http::response(['message' => 'unauthorized'], 401)]);

        $user = $this->makeUser();
        $this->actingAs($user);
        $device = WhatsappInstance::create(['name' => 'L', 'instance_name' => 'inst-2', 'status' => 'open']);
        $contact = Contact::create(['name' => 'Bob', 'phone' => '971500000010']);
        $campaign = Campaign::create([
            'whatsapp_instance_id' => $device->id, 'name' => 'C', 'type' => 'text',
            'body' => 'hi', 'status' => 'sending', 'total' => 1,
        ]);

        (new SendTransactionalNotification($device, $contact, 'Hello', $campaign))->handle();

        $this->assertSame('close', $device->fresh()->status);       // device marked Disconnected
        $this->assertSame('paused', $campaign->fresh()->status);    // campaign loop frozen
        $this->assertDatabaseHas('alerts', ['level' => 'error', 'tenant_id' => $user->tenant_id]);
    }

    public function test_bulk_settings_save(): void
    {
        $user = $this->makeUser();
        $this->actingAs($user);

        $this->put('/settings', [
            'bulk_delay_min' => 30, 'bulk_delay_max' => 60,
            'bulk_sleep_after' => 20, 'bulk_sleep_seconds' => 120,
            'bulk_hook_number' => '+971 50 1234567', 'bulk_spintax' => '1',
        ])->assertRedirect();

        $settings = $user->tenant->fresh()->settings;
        $this->assertSame(30, $settings['bulk_delay_min']);
        $this->assertSame('971501234567', $settings['bulk_hook_number']);
    }

    public function test_verify_marks_contacts_valid_or_invalid(): void
    {
        config(['evolution.base_url' => 'http://localhost:8080', 'evolution.api_key' => 'k']);
        Http::fake(['*/chat/whatsappNumbers/*' => Http::response([
            ['number' => '971500000001', 'exists' => true],
            ['number' => '971500000002', 'exists' => false],
        ], 200)]);

        $user = $this->makeUser();
        $this->actingAs($user);
        WhatsappInstance::create(['name' => 'L', 'instance_name' => 'inst-v', 'status' => 'open']);
        $c1 = Contact::create(['name' => 'A', 'phone' => '971500000001']);
        $c2 = Contact::create(['name' => 'B', 'phone' => '971500000002']);

        $this->post('/contacts/verify')->assertRedirect();

        $this->assertSame('valid', $c1->fresh()->wa_status);
        $this->assertSame('invalid', $c2->fresh()->wa_status);
    }

    public function test_verification_gate_excludes_unverified(): void
    {
        $user = $this->makeUser();
        $this->actingAs($user);
        $user->tenant->update(['settings' => ['allow_non_verified' => false]]); // strict mode

        $device = WhatsappInstance::create(['name' => 'L', 'instance_name' => 'inst-g', 'status' => 'open']);
        Contact::create(['name' => 'Valid', 'phone' => '971500000001', 'wa_status' => 'valid']);
        Contact::create(['name' => 'Unverified', 'phone' => '971500000002']); // wa_status defaults unverified

        Queue::fake();
        $this->post('/campaigns', [
            'name' => 'Strict', 'device_ids' => [$device->id],
            'body' => 'hi', 'audience' => 'all', 'min_delay' => 1, 'max_delay' => 2, 'schedule' => 'now',
        ])->assertRedirect();

        // Only the verified contact is included.
        $this->assertDatabaseHas('campaigns', ['name' => 'Strict', 'total' => 1]);
    }

    public function test_message_variants_rotate_on_send(): void
    {
        config(['evolution.base_url' => 'http://localhost:8080', 'evolution.api_key' => 'k']);
        Http::fake(['*' => Http::response(['key' => ['id' => 'X']], 201)]);

        $user = $this->makeUser();
        $this->actingAs($user);
        $user->tenant->update(['settings' => ['bulk_spintax' => false]]);

        $device = WhatsappInstance::create(['name' => 'L', 'instance_name' => 'inst-var', 'status' => 'open']);
        $contact = Contact::create(['name' => 'Bob', 'phone' => '971500000030']);
        $campaign = Campaign::create([
            'whatsapp_instance_id' => $device->id, 'name' => 'V', 'type' => 'text',
            'body' => 'Alpha', 'variants' => ['Bravo', 'Charlie'], 'status' => 'sending', 'total' => 1,
        ]);
        $recipient = $campaign->recipients()->create([
            'contact_id' => $contact->id, 'phone' => $contact->phone, 'status' => 'pending',
        ]);

        (new SendCampaignMessage($recipient->id))->handle();

        Http::assertSent(fn ($req) => str_contains($req->url(), '/message/sendText/inst-var')
            && in_array($req['text'], ['Alpha', 'Bravo', 'Charlie'], true));
    }

    public function test_owner_can_add_team_member(): void
    {
        $owner = $this->makeUser();
        $this->actingAs($owner);

        $this->post('/users', [
            'name' => 'Mia', 'email' => 'mia@corp.dev', 'role' => 'member',
            'password' => 'password', 'password_confirmation' => 'password',
        ])->assertRedirect('/users');

        $this->assertDatabaseHas('users', [
            'email' => 'mia@corp.dev', 'tenant_id' => $owner->tenant_id, 'role' => 'member',
        ]);
    }

    public function test_member_cannot_manage_team(): void
    {
        $owner = $this->makeUser('Acme', 'o@corp.dev');
        $member = User::create([
            'tenant_id' => $owner->tenant_id, 'name' => 'M', 'email' => 'm@corp.dev',
            'role' => 'member', 'password' => Hash::make('secret'),
        ]);

        $this->actingAs($member);
        $this->get('/users')->assertForbidden();
        $this->post('/users', ['name' => 'X', 'email' => 'x@x.dev', 'role' => 'member',
            'password' => 'password', 'password_confirmation' => 'password'])->assertForbidden();
    }

    public function test_cannot_manage_another_tenants_user(): void
    {
        $ownerA = $this->makeUser('A Corp', 'a@corp.dev');
        $ownerB = $this->makeUser('B Corp', 'b@corp.dev');

        $this->actingAs($ownerA);
        $this->get("/users/{$ownerB->id}/edit")->assertNotFound();
    }

    public function test_owner_can_download_backup(): void
    {
        $owner = $this->makeUser();
        $this->actingAs($owner);
        Contact::create(['name' => 'Bob', 'phone' => '971500000001']);

        $this->post('/backup/create')->assertOk()->assertHeader('content-type', 'application/zip');
    }

    public function test_order_webhook_creates_invoice_and_alert(): void
    {
        $owner = $this->makeUser();
        $this->actingAs($owner);
        $device = WhatsappInstance::create(['name' => 'L', 'instance_name' => 'shop-1', 'status' => 'open']);
        auth()->logout();

        $this->postJson('/webhooks/order', [
            'instance' => 'shop-1', 'phone' => '971509998877', 'name' => 'Sara', 'currency' => 'AED',
            'items' => [['name' => 'Cake', 'quantity' => 2, 'price' => 25.00]],
        ])->assertOk()->assertJson(['ok' => true]);

        $this->assertDatabaseHas('invoices', ['tenant_id' => $owner->tenant_id, 'currency' => 'AED', 'total' => 50.00]);
        $this->assertDatabaseHas('alerts', ['tenant_id' => $owner->tenant_id, 'level' => 'info']);
    }

    public function test_api_requires_token_and_returns_tenant(): void
    {
        $owner = $this->makeUser();
        $this->getJson('/api/me')->assertStatus(401);

        $this->actingAs($owner);
        [, $plain] = ApiToken::generate('test');
        auth()->logout();

        $this->withToken($plain)->getJson('/api/me')
            ->assertOk()->assertJson(['tenant_id' => $owner->tenant_id]);
    }

    public function test_api_sends_message(): void
    {
        config(['evolution.base_url' => 'http://localhost:8080', 'evolution.api_key' => 'k']);
        Http::fake(['*' => Http::response(['key' => ['id' => 'X']], 201)]);

        $owner = $this->makeUser();
        $this->actingAs($owner);
        $device = WhatsappInstance::create(['name' => 'L', 'instance_name' => 'api-dev', 'status' => 'open']);
        [$model, $plain] = ApiToken::generate('t');
        auth()->logout();

        $this->withToken($plain)->postJson('/api/messages', [
            'device_id' => $device->id, 'phone' => '971500000000', 'message' => 'Hi',
        ])->assertOk()->assertJson(['ok' => true]);

        Http::assertSent(fn ($r) => str_contains($r->url(), '/message/sendText/api-dev'));
    }

    public function test_owner_can_create_api_token(): void
    {
        $owner = $this->makeUser();
        $this->actingAs($owner);

        $this->post('/api-tokens', ['name' => 'Zapier'])->assertRedirect();
        $this->assertDatabaseHas('api_tokens', ['tenant_id' => $owner->tenant_id, 'name' => 'Zapier']);
    }

    public function test_totp_2fa_challenge_on_login(): void
    {
        $user = $this->makeUser('Acme', 'tfa@corp.dev');
        $secret = Totp::secret();
        $user->update(['two_factor_enabled' => true, 'two_factor_type' => 'totp', 'two_factor_secret' => encrypt($secret)]);

        // Password step redirects to the 2FA challenge, not straight in.
        $this->post('/login', ['email' => 'tfa@corp.dev', 'password' => 'password'])
            ->assertRedirect(route('two-factor.show'));
        $this->assertGuest();

        // Correct authenticator code completes login.
        $code = Totp::at($secret, (int) floor(time() / 30));
        $this->post('/two-factor-challenge', ['code' => $code])->assertRedirect(route('dashboard', absolute: false));
        $this->assertAuthenticatedAs($user->fresh());
    }

    public function test_device_pairing_code_login(): void
    {
        config(['evolution.base_url' => 'http://localhost:8080', 'evolution.api_key' => 'k']);
        Http::fake(['*instance/create*' => Http::response(['hash' => ['apikey' => 'x'], 'qrcode' => ['pairingCode' => 'ABCD-1234']], 201)]);

        $owner = $this->makeUser();
        $this->actingAs($owner);

        $this->post('/devices', ['name' => 'Line', 'phone_for_pairing' => '+971 50 123 4567'])
            ->assertRedirect('/devices');

        $device = WhatsappInstance::withoutGlobalScopes()->where('name', 'Line')->first();
        $this->assertSame('ABCD-1234', $device->pairing_code);
        Http::assertSent(fn ($r) => str_contains($r->url(), '/instance/create') && ($r['number'] ?? null) === '971501234567');
    }

    public function test_device_daily_cap_defers_send(): void
    {
        config(['evolution.base_url' => 'http://localhost:8080', 'evolution.api_key' => 'k']);
        Http::fake(['*' => Http::response(['key' => ['id' => 'X']], 201)]);
        Queue::fake();

        $owner = $this->makeUser();
        $this->actingAs($owner);
        $device = WhatsappInstance::create(['name' => 'L', 'instance_name' => 'cap-1', 'status' => 'open', 'daily_limit' => 1]);
        $contact = Contact::create(['name' => 'C', 'phone' => '971500000060']);
        // Already at the cap for today.
        \App\Models\Message::create(['whatsapp_instance_id' => $device->id, 'direction' => 'out', 'phone' => 'x', 'type' => 'text', 'status' => 'sent']);

        $campaign = Campaign::create(['whatsapp_instance_id' => $device->id, 'name' => 'C', 'type' => 'text', 'body' => 'hi', 'status' => 'sending', 'total' => 1]);
        $recipient = $campaign->recipients()->create([
            'whatsapp_instance_id' => $device->id, 'contact_id' => $contact->id, 'phone' => $contact->phone, 'status' => 'pending',
        ]);

        (new SendCampaignMessage($recipient->id))->handle();

        Http::assertNothingSent();                        // capped → not sent now
        Queue::assertPushed(SendCampaignMessage::class);  // deferred to tomorrow
        $this->assertSame('pending', $recipient->fresh()->status);
    }

    public function test_multi_device_sticky_assignment(): void
    {
        $owner = $this->makeUser();
        $this->actingAs($owner);
        $d1 = WhatsappInstance::create(['name' => 'A', 'instance_name' => 'md-1', 'status' => 'open']);
        $d2 = WhatsappInstance::create(['name' => 'B', 'instance_name' => 'md-2', 'status' => 'open']);
        Contact::create(['name' => 'C1', 'phone' => '971500000001', 'wa_status' => 'valid']);
        Contact::create(['name' => 'C2', 'phone' => '971500000002', 'wa_status' => 'valid']);

        Queue::fake();
        $this->post('/campaigns', [
            'name' => 'Multi', 'device_ids' => [$d1->id, $d2->id],
            'body' => 'hi', 'audience' => 'all', 'min_delay' => 1, 'max_delay' => 2, 'schedule' => 'now',
        ])->assertRedirect();

        $recipients = \App\Models\CampaignRecipient::withoutGlobalScopes()->get();
        $this->assertCount(2, $recipients);
        foreach ($recipients as $r) {
            $this->assertContains($r->whatsapp_instance_id, [$d1->id, $d2->id]);
        }
    }

    public function test_ai_variants_requires_openai_key(): void
    {
        config(['services.openai.key' => null]);
        $this->actingAs($this->makeUser());

        $this->postJson('/templates/variants', ['message' => 'Hello there', 'count' => 5])
            ->assertStatus(422)->assertJsonStructure(['error']);
    }

    public function test_ai_variants_generates_from_message(): void
    {
        config(['services.openai.key' => 'k']);
        Http::fake(['*' => Http::response([
            'choices' => [['message' => ['content' => '["Hi there","Hello friend","Hey you"]']]],
        ], 200)]);
        $this->actingAs($this->makeUser());

        $this->postJson('/templates/variants', ['message' => 'Hello', 'count' => 3])
            ->assertOk()
            ->assertJson(['variants' => ['Hi there', 'Hello friend', 'Hey you']]);
    }

    public function test_ai_variants_via_claude_provider(): void
    {
        Http::fake(['api.anthropic.com/*' => Http::response(['content' => [['text' => '["A","B"]']]], 200)]);

        $owner = $this->makeUser();
        $owner->tenant->update(['settings' => ['ai_provider' => 'claude', 'ai_claude_key' => 'sk-ant-x']]);
        $this->actingAs($owner);

        $this->postJson('/templates/variants', ['message' => 'Hi', 'count' => 2])
            ->assertOk()->assertJson(['variants' => ['A', 'B']]);

        Http::assertSent(fn ($r) => str_contains($r->url(), 'api.anthropic.com') && $r->hasHeader('x-api-key', 'sk-ant-x'));
    }

    public function test_buttons_template_can_be_created(): void
    {
        $user = $this->makeUser();
        $this->actingAs($user);

        $this->post('/templates', [
            'name' => 'Btn', 'type' => 'buttons', 'body' => 'Pick one', 'buttons_title' => 'Menu',
            'buttons' => [
                ['type' => 'reply', 'text' => 'Yes', 'value' => ''],
                ['type' => 'url', 'text' => 'Visit', 'value' => 'https://x.co'],
            ],
        ])->assertRedirect('/templates');

        $template = Template::withoutGlobalScopes()->where('name', 'Btn')->first();
        $this->assertSame('buttons', $template->type);
        $this->assertCount(2, $template->buttons['items']);
        $this->assertSame('Menu', $template->buttons['title']);
    }

    public function test_buttons_campaign_sends_via_buttons_endpoint(): void
    {
        config(['evolution.base_url' => 'http://localhost:8080', 'evolution.api_key' => 'k']);
        Http::fake(['*' => Http::response(['key' => ['id' => 'X']], 201)]);

        $user = $this->makeUser();
        $this->actingAs($user);
        $device = WhatsappInstance::create(['name' => 'L', 'instance_name' => 'inst-btn', 'status' => 'open']);
        $contact = Contact::create(['name' => 'Bob', 'phone' => '971500000040']);
        $campaign = Campaign::create([
            'whatsapp_instance_id' => $device->id, 'name' => 'B', 'type' => 'buttons', 'body' => 'Pick one',
            'buttons' => ['title' => 'Menu', 'footer' => null, 'items' => [
                ['type' => 'reply', 'text' => 'Yes', 'value' => null],
                ['type' => 'url', 'text' => 'Visit', 'value' => 'https://x.co'],
            ]],
            'status' => 'sending', 'total' => 1,
        ]);
        $recipient = $campaign->recipients()->create([
            'contact_id' => $contact->id, 'phone' => $contact->phone, 'status' => 'pending',
        ]);

        (new SendCampaignMessage($recipient->id))->handle();

        Http::assertSent(fn ($req) => str_contains($req->url(), '/message/sendButtons/inst-btn')
            && count($req['buttons']) === 2
            && $req['buttons'][0]['displayText'] === 'Yes'
            && $req['buttons'][1]['url'] === 'https://x.co');
    }

    public function test_carousel_template_can_be_created(): void
    {
        $user = $this->makeUser();
        $this->actingAs($user);

        $this->post('/templates', [
            'name' => 'Car', 'type' => 'carousel',
            'cards' => [
                ['image' => 'https://x.co/1.jpg', 'title' => 'A', 'body' => 'card a', 'buttons' => [['type' => 'url', 'text' => 'Buy', 'value' => 'https://x.co']]],
                ['image' => 'https://x.co/2.jpg', 'title' => 'B', 'body' => 'card b', 'buttons' => []],
            ],
        ])->assertRedirect('/templates');

        $template = Template::withoutGlobalScopes()->where('name', 'Car')->first();
        $this->assertSame('carousel', $template->type);
        $this->assertCount(2, $template->cards);
    }

    public function test_carousel_campaign_sends_each_card_with_fallback(): void
    {
        config(['evolution.base_url' => 'http://localhost:8080', 'evolution.api_key' => 'k']);
        Http::fake(['*' => Http::response(['key' => ['id' => 'X']], 201)]);

        $user = $this->makeUser();
        $this->actingAs($user);
        $device = WhatsappInstance::create(['name' => 'L', 'instance_name' => 'inst-car', 'status' => 'open']);
        $contact = Contact::create(['name' => 'Bob', 'phone' => '971500000050']);
        $campaign = Campaign::create([
            'whatsapp_instance_id' => $device->id, 'name' => 'C', 'type' => 'carousel',
            'cards' => [
                ['image' => 'https://x.co/1.jpg', 'title' => 'A', 'body' => 'a', 'buttons' => [['type' => 'url', 'text' => 'Buy', 'value' => 'https://x.co']]],
                ['image' => 'https://x.co/2.jpg', 'title' => 'B', 'body' => 'b', 'buttons' => []],
            ],
            'status' => 'sending', 'total' => 1,
        ]);
        $recipient = $campaign->recipients()->create([
            'contact_id' => $contact->id, 'phone' => $contact->phone, 'status' => 'pending',
        ]);

        (new SendCampaignMessage($recipient->id))->handle();

        Http::assertSentCount(2); // one media message per card
        Http::assertSent(fn ($req) => str_contains($req->url(), '/message/sendMedia/inst-car'));
        $this->assertSame('sent', $recipient->fresh()->status);
        $this->assertStringContainsString('fallback', (string) $recipient->fresh()->error);
    }

    public function test_poll_campaign_sends_image_caption_then_poll(): void
    {
        config(['evolution.base_url' => 'http://localhost:8080', 'evolution.api_key' => 'k']);
        Http::fake(['*' => Http::response(['key' => ['id' => 'X']], 201)]);

        $owner = $this->makeUser();
        $this->actingAs($owner);
        $device = WhatsappInstance::create(['name' => 'L', 'instance_name' => 'inst-poll', 'status' => 'open']);
        $contact = Contact::create(['name' => 'Bob', 'phone' => '971500000070']);
        $campaign = Campaign::create([
            'whatsapp_instance_id' => $device->id, 'name' => 'P', 'type' => 'poll',
            'body' => 'Dear {{name}}, 30% off!', 'media_url' => 'https://x.co/promo.jpg', 'media_type' => 'image',
            'poll' => ['question' => 'Interested?', 'options' => ['Yes', 'No'], 'multiple' => false],
            'status' => 'sending', 'total' => 1,
        ]);
        $recipient = $campaign->recipients()->create([
            'whatsapp_instance_id' => $device->id, 'contact_id' => $contact->id, 'phone' => $contact->phone, 'status' => 'pending',
        ]);

        (new SendCampaignMessage($recipient->id))->handle();

        // image with the personalised text as caption ...
        Http::assertSent(fn ($r) => str_contains($r->url(), '/message/sendMedia/inst-poll')
            && str_contains((string) ($r['caption'] ?? ''), 'Dear Bob'));
        // ... then the poll
        Http::assertSent(fn ($r) => str_contains($r->url(), '/message/sendPoll/inst-poll'));
    }

    public function test_upload_returns_public_url(): void
    {
        Storage::fake('public');
        $this->actingAs($this->makeUser());

        $this->post('/uploads', ['file' => UploadedFile::fake()->image('promo.jpg')])
            ->assertOk()->assertJsonStructure(['url', 'name']);
    }

    public function test_group_import_and_verify(): void
    {
        $owner = $this->makeUser();
        $this->actingAs($owner);
        $group = ContactGroup::create(['name' => 'VIP']);

        $csv = UploadedFile::fake()->createWithContent('contacts.csv', "name,phone\nBob,971500000001\nSue,971500000002\n");
        $this->post("/groups/{$group->id}/import", ['file' => $csv])->assertRedirect();
        $this->assertSame(2, $group->contacts()->count());

        WhatsappInstance::create(['name' => 'L', 'instance_name' => 'g-dev', 'status' => 'open']);
        Queue::fake();
        $this->post("/groups/{$group->id}/verify")->assertRedirect();
        Queue::assertPushed(\App\Jobs\VerifyContactsBatch::class);
    }

    public function test_template_edit_studio_and_preview_render(): void
    {
        $owner = $this->makeUser();
        $this->actingAs($owner);
        $t = Template::create([
            'tenant_id' => $owner->tenant_id, 'name' => 'MediaTpl', 'type' => 'media',
            'media_type' => 'image', 'media_url' => 'https://example.com/a.jpg', 'body' => 'Hello',
        ]);

        $this->get("/templates/{$t->id}/edit")->assertOk()->assertSee('MediaTpl');
        $this->get("/templates/{$t->id}/preview")->assertOk()->assertSee('https://example.com/a.jpg');
    }

    public function test_clone_template(): void
    {
        $owner = $this->makeUser();
        $this->actingAs($owner);
        $t = Template::create(['tenant_id' => $owner->tenant_id, 'name' => 'Welcome', 'type' => 'text', 'body' => 'Hi']);

        $this->post("/templates/{$t->id}/clone")->assertRedirect();
        $this->assertDatabaseHas('templates', ['name' => 'Welcome (copy)', 'tenant_id' => $owner->tenant_id]);
    }

    public function test_campaign_retry_failed_and_export(): void
    {
        Queue::fake();
        $owner = $this->makeUser();
        $this->actingAs($owner);
        $device = WhatsappInstance::create(['name' => 'L', 'instance_name' => 'r-dev', 'status' => 'open']);
        $campaign = Campaign::create(['whatsapp_instance_id' => $device->id, 'name' => 'C', 'type' => 'text', 'body' => 'hi', 'status' => 'completed', 'total' => 1, 'failed' => 1]);
        $rec = $campaign->recipients()->create(['phone' => '971500000001', 'status' => 'failed', 'error' => 'x']);

        $this->post("/campaigns/{$campaign->id}/retry")->assertRedirect();
        $this->assertSame('pending', $rec->fresh()->status);
        Queue::assertPushed(SendCampaignMessage::class);

        $this->get("/campaigns/{$campaign->id}/export")->assertOk();
    }

    public function test_campaign_test_send(): void
    {
        config(['evolution.base_url' => 'http://localhost:8080', 'evolution.api_key' => 'k']);
        Http::fake(['*' => Http::response(['key' => ['id' => 'X']], 201)]);
        $owner = $this->makeUser();
        $this->actingAs($owner);
        $device = WhatsappInstance::create(['name' => 'L', 'instance_name' => 't-dev', 'status' => 'open']);
        $campaign = Campaign::create(['whatsapp_instance_id' => $device->id, 'name' => 'C', 'type' => 'text', 'body' => 'Hi', 'status' => 'draft', 'total' => 0]);

        $this->post("/campaigns/{$campaign->id}/test", ['phone' => '971500000009'])->assertRedirect();
        Http::assertSent(fn ($r) => str_contains($r->url(), '/message/sendText/t-dev'));
    }

    public function test_suppressed_number_is_excluded_from_campaign(): void
    {
        Queue::fake();
        $owner = $this->makeUser();
        $this->actingAs($owner);
        $device = WhatsappInstance::create(['name' => 'L', 'instance_name' => 'sup-dev', 'status' => 'open']);
        Contact::create(['name' => 'A', 'phone' => '971500000001']);
        Contact::create(['name' => 'B', 'phone' => '971500000002']);
        Suppression::create(['phone' => '971500000002', 'source' => 'manual']);

        $this->post('/campaigns', [
            'name' => 'C', 'device_ids' => [$device->id], 'body' => 'hi',
            'audience' => 'all', 'min_delay' => 1, 'max_delay' => 2, 'schedule' => 'now',
        ])->assertRedirect();

        $this->assertSame(1, Campaign::first()->total); // suppressed contact excluded
    }

    public function test_tag_targeting_limits_recipients(): void
    {
        Queue::fake();
        $owner = $this->makeUser();
        $this->actingAs($owner);
        $device = WhatsappInstance::create(['name' => 'L', 'instance_name' => 'tag-dev', 'status' => 'open']);
        Contact::create(['name' => 'A', 'phone' => '971500000001', 'tags' => ['vip']]);
        Contact::create(['name' => 'B', 'phone' => '971500000002', 'tags' => ['lead']]);

        $this->post('/campaigns', [
            'name' => 'C', 'device_ids' => [$device->id], 'body' => 'hi',
            'audience' => 'tag', 'tag' => 'vip', 'min_delay' => 1, 'max_delay' => 2, 'schedule' => 'now',
        ])->assertRedirect();

        $this->assertSame(1, Campaign::first()->total);
    }

    public function test_link_tracking_wraps_and_click_redirects(): void
    {
        Queue::fake();
        $owner = $this->makeUser();
        $this->actingAs($owner);
        $device = WhatsappInstance::create(['name' => 'L', 'instance_name' => 'lt-dev', 'status' => 'open']);
        Contact::create(['name' => 'A', 'phone' => '971500000001']);

        $this->post('/campaigns', [
            'name' => 'C', 'device_ids' => [$device->id], 'body' => 'Visit https://example.com/deal now',
            'audience' => 'all', 'track_links' => '1', 'min_delay' => 1, 'max_delay' => 2, 'schedule' => 'now',
        ])->assertRedirect();

        $link = TrackedLink::first();
        $this->assertNotNull($link);
        $this->assertSame('https://example.com/deal', $link->url);

        $this->get('/l/'.$link->token)->assertRedirect('https://example.com/deal');
        $this->assertSame(1, $link->fresh()->clicks);
    }

    public function test_manual_suppression_and_audit_logged(): void
    {
        $owner = $this->makeUser();
        $this->actingAs($owner);

        $this->post('/suppressions', ['phone' => '+971 50 111 2233', 'reason' => 'requested'])->assertRedirect();

        $this->assertDatabaseHas('suppressions', ['tenant_id' => $owner->tenant_id, 'phone' => '971501112233']);
        $this->assertDatabaseHas('activity_logs', ['tenant_id' => $owner->tenant_id, 'action' => 'suppression.added']);
    }

    public function test_sequence_create_enroll_and_dispatch(): void
    {
        config(['evolution.base_url' => 'http://localhost:8080', 'evolution.api_key' => 'k']);
        Http::fake(['*' => Http::response(['key' => ['id' => 'X']], 201)]);
        $owner = $this->makeUser();
        $this->actingAs($owner);
        WhatsappInstance::create(['name' => 'L', 'instance_name' => 'seq-dev', 'status' => 'open']);
        Contact::create(['name' => 'A', 'phone' => '971500000001']);

        $this->post('/sequences', [
            'name' => 'Onboarding', 'is_active' => '1',
            'steps' => [['delay_minutes' => 0, 'body' => 'Welcome {{name}}']],
        ])->assertRedirect();

        $sequence = Sequence::first();
        $this->assertSame(1, $sequence->steps()->count());

        $this->post("/sequences/{$sequence->id}/enroll", [])->assertRedirect();
        $this->assertSame(1, $sequence->enrollments()->count());

        $sequence->enrollments()->update(['next_run_at' => now()->subMinute()]);
        app(SequenceService::class)->dispatchDue();

        $this->assertSame('completed', $sequence->enrollments()->first()->status);
        Http::assertSent(fn ($r) => str_contains($r->url(), '/message/sendText/seq-dev'));
    }

    public function test_media_upload_records_asset(): void
    {
        Storage::fake('public');
        $owner = $this->makeUser();
        $this->actingAs($owner);

        $this->post('/media', ['file' => UploadedFile::fake()->image('a.jpg')])->assertRedirect();
        $this->assertDatabaseHas('media_assets', ['tenant_id' => $owner->tenant_id, 'name' => 'a.jpg']);
    }

    public function test_contact_tags_and_custom_fields_saved(): void
    {
        $owner = $this->makeUser();
        $this->actingAs($owner);

        $this->post('/contacts', [
            'name' => 'Bob', 'phone' => '971500000001',
            'tags' => 'vip, dubai',
            'attr_keys' => ['company', 'city'], 'attr_values' => ['Acme', 'Dubai'],
        ])->assertRedirect('/contacts');

        $c = Contact::first();
        $this->assertEqualsCanonicalizing(['vip', 'dubai'], $c->tags);
        $this->assertSame('Acme', $c->attributes['company']);
    }

    public function test_billing_plan_switch(): void
    {
        $owner = $this->makeUser();
        $this->actingAs($owner);

        $this->put('/billing', ['plan' => 'pro'])->assertRedirect();
        $this->assertSame('pro', $owner->tenant->fresh()->plan);
    }

    public function test_device_limit_blocks_second_on_free_plan(): void
    {
        $owner = $this->makeUser();
        $owner->tenant->update(['plan' => 'free']);
        $this->actingAs($owner);
        WhatsappInstance::create(['name' => 'One', 'instance_name' => 'd1', 'status' => 'open']);

        $this->post('/devices', ['name' => 'Two'])->assertRedirect();
        $this->assertSame(1, WhatsappInstance::count()); // plan cap blocks the second
    }

    public function test_webhook_endpoint_created_and_event_queues(): void
    {
        $owner = $this->makeUser();
        $this->actingAs($owner);

        $this->post('/webhook-endpoints', ['url' => 'https://example.com/hook', 'events' => ['message.received']])
            ->assertRedirect();
        $this->assertDatabaseHas('webhook_endpoints', ['tenant_id' => $owner->tenant_id, 'url' => 'https://example.com/hook']);

        Queue::fake();
        DispatchWebhook::fire($owner->tenant_id, 'message.received', ['x' => 1]);
        Queue::assertPushed(DispatchWebhook::class);
    }

    public function test_inbound_stop_keyword_opts_out_and_suppresses(): void
    {
        config(['evolution.base_url' => 'http://localhost:8080', 'evolution.api_key' => 'k', 'evolution.webhook_secret' => null]);
        Http::fake(['*' => Http::response(['key' => ['id' => 'X']], 201)]);
        $owner = $this->makeUser();
        WhatsappInstance::create(['tenant_id' => $owner->tenant_id, 'name' => 'L', 'instance_name' => 'wh-dev', 'status' => 'open']);

        $this->postJson('/webhooks/evolution', [
            'event'    => 'messages.upsert',
            'instance' => 'wh-dev',
            'data'     => [
                'key'      => ['remoteJid' => '971500000009@s.whatsapp.net', 'fromMe' => false, 'id' => 'm1'],
                'message'  => ['conversation' => 'STOP'],
                'pushName' => 'Test',
            ],
        ])->assertOk();

        $this->assertDatabaseHas('contacts', ['phone' => '971500000009', 'opted_out' => 1]);
        $this->assertDatabaseHas('suppressions', ['phone' => '971500000009', 'source' => 'opt_out']);
    }

    public function test_quiet_hours_window(): void
    {
        $this->travelTo(\Illuminate\Support\Carbon::parse('2026-07-01 12:00:00'));

        $this->assertNull(SendingWindow::nextAllowed(['quiet_hours_enabled' => false]));
        $this->assertNull(SendingWindow::nextAllowed(['quiet_hours_enabled' => true, 'quiet_start' => '20:00', 'quiet_end' => '23:00']));
        $this->assertNotNull(SendingWindow::nextAllowed(['quiet_hours_enabled' => true, 'quiet_start' => '08:00', 'quiet_end' => '18:00']));

        $this->travelBack();
    }

    public function test_super_admin_can_create_a_workspace_login(): void
    {
        $admin = $this->makeUser('HQ', 'admin@test.dev');
        $admin->update(['is_super_admin' => true]);
        $this->actingAs($admin);

        $this->get('/admin')->assertOk();

        $this->post('/admin/workspaces', [
            'name'        => 'Colleague Co',
            'owner_name'  => 'Colleague',
            'owner_email' => 'colleague@test.dev',
            'password'    => 'password123',
            'plan_type'   => 'monthly',
            'max_devices' => 3,
            'modules'     => ['devices', 'campaigns'],
        ])->assertRedirect();

        $this->assertDatabaseHas('tenants', ['name' => 'Colleague Co', 'max_devices' => 3]);
        $this->assertDatabaseHas('users', ['email' => 'colleague@test.dev', 'role' => 'owner']);
    }

    public function test_non_admin_cannot_reach_admin_panel(): void
    {
        $this->actingAs($this->makeUser());
        $this->get('/admin')->assertForbidden();
        $this->get('/admin/workspaces')->assertForbidden();
    }

    public function test_suspended_workspace_is_blocked(): void
    {
        $owner = $this->makeUser();
        $owner->tenant->update(['status' => 'suspended']);
        $this->actingAs($owner);

        $this->get('/campaigns')->assertRedirect(route('subscription.inactive'));
        $this->get('/subscription/inactive')->assertOk();
    }

    public function test_expired_workspace_is_blocked(): void
    {
        $owner = $this->makeUser();
        $owner->tenant->update(['expires_at' => now()->subDay()]);
        $this->actingAs($owner);

        $this->get('/dashboard')->assertRedirect(route('subscription.inactive'));
    }

    public function test_disabled_module_route_is_forbidden(): void
    {
        $owner = $this->makeUser();
        $owner->tenant->update(['enabled_modules' => ['contacts']]);
        $this->actingAs($owner);

        $this->get('/contacts')->assertOk();       // enabled
        $this->get('/campaigns')->assertForbidden(); // not in plan
    }

    public function test_workspace_device_limit_overrides_plan(): void
    {
        $owner = $this->makeUser();
        $owner->tenant->update(['plan' => 'free', 'max_devices' => 7]);
        $this->actingAs($owner);

        $this->assertSame(7, \App\Services\PlanLimit::for($owner->tenant->fresh())->limit('devices'));
    }

    public function test_make_admin_command_promotes_user(): void
    {
        $user = $this->makeUser('X', 'promote@test.dev');
        $this->artisan('eagle:make-admin', ['email' => 'promote@test.dev'])->assertOk();

        $this->assertTrue($user->fresh()->isSuperAdmin());
    }

    public function test_table_filters_apply_and_render(): void
    {
        $owner = $this->makeUser();
        $this->actingAs($owner);
        Template::create(['tenant_id' => $owner->tenant_id, 'name' => 'WelcomeMsg', 'type' => 'text', 'body' => 'hi']);
        Template::create(['tenant_id' => $owner->tenant_id, 'name' => 'PollMsg', 'type' => 'poll', 'poll' => ['question' => 'Q', 'options' => ['a', 'b']]]);

        $this->get('/templates?type=text')->assertOk()->assertSee('WelcomeMsg')->assertDontSee('PollMsg');
        $this->get('/templates?q=Poll')->assertOk()->assertSee('PollMsg')->assertDontSee('WelcomeMsg');

        // Filtered index pages render across modules.
        foreach ([
            '/campaigns?status=draft', '/groups?q=x', '/sequences?status=active',
            '/users?role=owner', '/media?kind=image', '/suppressions?source=manual',
            '/audit?created_from=2020-01-01', '/invoices?status=paid', '/chatbot?status=active',
        ] as $url) {
            $this->get($url)->assertOk();
        }
    }

    public function test_contact_import_skips_duplicates_and_accepts_number_column(): void
    {
        $owner = $this->makeUser();
        $this->actingAs($owner);
        Contact::create(['tenant_id' => $owner->tenant_id, 'phone' => '971500000001', 'name' => 'Existing']);

        $csv = UploadedFile::fake()->createWithContent('c.csv',
            "name,number\nExisting Again,971500000001\nNewOne,971500000002\nNewOne Dup,971500000002\n");

        $this->post('/contacts/import', ['file' => $csv])->assertRedirect('/contacts');

        // Existing (dupe) + in-file dupe skipped; only the one new number added.
        $this->assertSame(2, Contact::count());
        $this->assertDatabaseHas('contacts', ['phone' => '971500000002', 'name' => 'NewOne']);
    }

    public function test_contact_sample_and_export_download(): void
    {
        $owner = $this->makeUser();
        $this->actingAs($owner);
        Contact::create(['tenant_id' => $owner->tenant_id, 'phone' => '971500000001', 'name' => 'Bob']);

        $sample = $this->get('/contacts/import/sample')->assertOk();
        $this->assertStringContainsString('number', $sample->streamedContent());

        $export = $this->get('/contacts/export')->assertOk();
        $this->assertStringContainsString('971500000001', $export->streamedContent());
    }

    public function test_settings_test_buttons_guard_missing_config(): void
    {
        $this->actingAs($this->makeUser());
        $this->postJson('/settings/test-email')->assertStatus(422)->assertJson(['ok' => false]);
        $this->postJson('/settings/test-ai')->assertStatus(422)->assertJson(['ok' => false]);
    }

    public function test_unsubscribe_within_sentence_opts_out(): void
    {
        config(['evolution.base_url' => 'http://localhost:8080', 'evolution.api_key' => 'k', 'evolution.webhook_secret' => null]);
        Http::fake(['*' => Http::response(['key' => ['id' => 'X']], 201)]);
        $owner = $this->makeUser();
        WhatsappInstance::create(['tenant_id' => $owner->tenant_id, 'name' => 'L', 'instance_name' => 'un-dev', 'status' => 'open']);

        $this->postJson('/webhooks/evolution', [
            'event' => 'messages.upsert', 'instance' => 'un-dev',
            'data' => [
                'key' => ['remoteJid' => '971500000055@s.whatsapp.net', 'fromMe' => false, 'id' => 'm1'],
                'message' => ['conversation' => 'please unsubscribe me from this'],
            ],
        ])->assertOk();

        $this->assertDatabaseHas('contacts', ['phone' => '971500000055', 'opted_out' => 1]);
        $this->assertDatabaseHas('suppressions', ['phone' => '971500000055', 'source' => 'opt_out']);
    }

    public function test_help_center(): void
    {
        $this->actingAs($this->makeUser());
        $this->get('/help')->assertOk()->assertSee('Help center');
        $this->get('/help/devices')->assertOk()->assertSee('Connecting a WhatsApp number');
        $this->get('/help/does-not-exist')->assertNotFound();
        $this->postJson('/help/ask', ['question' => 'How do I import contacts?'])->assertStatus(422); // no AI key configured
    }

    public function test_campaign_rotates_variants_on_every_message(): void
    {
        Queue::fake();
        $owner = $this->makeUser();
        $this->actingAs($owner);
        $device = WhatsappInstance::create(['name' => 'L', 'instance_name' => 'var-dev', 'status' => 'open']);
        for ($i = 1; $i <= 6; $i++) {
            Contact::create(['phone' => '97155000000'.$i, 'name' => "C{$i}"]);
        }
        // Template pool = [main body, Var A, Var B] = 3 variants.
        $t = Template::create(['tenant_id' => $owner->tenant_id, 'name' => 'V', 'type' => 'text', 'body' => 'Main', 'variants' => ['Var A', 'Var B']]);

        $this->post('/campaigns', [
            'name' => 'V', 'device_ids' => [$device->id], 'template_id' => $t->id,
            'audience' => 'all', 'min_delay' => 1, 'max_delay' => 2, 'schedule' => 'now',
        ])->assertRedirect();

        $slots = Campaign::first()->recipients()->orderBy('id')->pluck('variant_index')->all();
        $this->assertSame([0, 1, 2, 0, 1, 2], $slots); // rotates every message, cycling the pool
    }

    public function test_campaign_rotates_device_every_n_messages(): void
    {
        Queue::fake();
        $owner = $this->makeUser();
        $this->actingAs($owner);
        $d1 = WhatsappInstance::create(['name' => 'A', 'instance_name' => 'rot-a', 'status' => 'open']);
        $d2 = WhatsappInstance::create(['name' => 'B', 'instance_name' => 'rot-b', 'status' => 'open']);
        for ($i = 1; $i <= 4; $i++) {
            Contact::create(['phone' => '97150000000'.$i, 'name' => "C{$i}"]);
        }

        $this->post('/campaigns', [
            'name' => 'Rot', 'device_ids' => [$d1->id, $d2->id], 'body' => 'hi',
            'audience' => 'all', 'rotate_every' => 2, 'min_delay' => 1, 'max_delay' => 2, 'schedule' => 'now',
        ])->assertRedirect();

        $devices = Campaign::first()->recipients()->orderBy('id')->pluck('whatsapp_instance_id')->all();
        $this->assertSame([$d1->id, $d1->id, $d2->id, $d2->id], $devices); // 2 from A, then 2 from B
    }

    public function test_spam_score_rates_clean_vs_spammy(): void
    {
        $service = new SpamScoreService;

        $clean = $service->analyze('Hi {{name}}, thanks for stopping by today. See you soon.');
        $this->assertSame('low', $clean['level']);

        $spammy = $service->analyze('CONGRATULATIONS!!! You are a WINNER of a FREE prize. Click here http://x.co/win and claim now — limited time $1000 cash!!!');
        $this->assertSame('high', $spammy['level']);
        $this->assertGreaterThanOrEqual(1, $spammy['stats']['links']);
        $this->assertNotEmpty($spammy['issues']);
    }

    public function test_spam_checker_returns_result(): void
    {
        $user = $this->makeUser();
        $this->actingAs($user);

        $this->post('/spam-checker', ['message' => 'CONGRATULATIONS WINNER!!! Claim your FREE PRIZE now. Click here http://x.co and http://y.co — limited time CASH guarantee!!!'])
            ->assertOk()
            ->assertSee('High risk');
    }

    public function test_spintax_and_merge_tags_resolve_on_send(): void
    {
        config(['evolution.base_url' => 'http://localhost:8080', 'evolution.api_key' => 'k']);
        Http::fake(['*' => Http::response(['key' => ['id' => 'X']], 201)]);

        $user = $this->makeUser();
        $this->actingAs($user);
        $user->tenant->update(['settings' => ['bulk_spintax' => false]]); // deterministic: first option

        $device = WhatsappInstance::create(['name' => 'L', 'instance_name' => 'inst-s', 'status' => 'open']);
        $contact = Contact::create(['name' => 'Bob', 'phone' => '971500000020']);
        $campaign = Campaign::create([
            'whatsapp_instance_id' => $device->id, 'name' => 'S', 'type' => 'text',
            'body' => '{Hi|Hello} {{name}}', 'status' => 'sending', 'total' => 1,
        ]);
        $recipient = $campaign->recipients()->create([
            'contact_id' => $contact->id, 'phone' => $contact->phone, 'status' => 'pending',
        ]);

        (new SendCampaignMessage($recipient->id))->handle();

        Http::assertSent(fn ($req) => str_contains($req->url(), '/message/sendText/inst-s')
            && $req['text'] === 'Hi Bob');   // spintax -> first option, merge tag applied
    }
}
