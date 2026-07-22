<?php

namespace Tests\Feature;

use App\Jobs\SendCampaignMessage;
use App\Models\Campaign;
use App\Models\Contact;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WhatsappInstance;
use App\Support\Personalizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Workspace-level "common spintax": the greeting and the wording groups are
 * defined ONCE in Settings and must reach EVERY campaign variant — the author
 * never pastes {a|b} braces into hundreds of variants.
 *
 * Deterministic assertions: bulk_spintax=false makes every rotation pick the
 * FIRST option.
 */
class CommonSpintaxTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private WhatsappInstance $device;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fakeGateway();

        $tenant = Tenant::create([
            'name' => 'Acme',
            'slug' => 'acme-common-spintax',
            'settings' => [
                'bulk_spintax'        => false,               // deterministic: first option
                'bulk_greeting'       => '{Hi|Hello} {{name}},',
                'bulk_spintax_groups' => "Check|Explore\nBook a demo|Request a demo",
                'bulk_random_prefix'  => 'EG-',
            ],
        ]);

        $this->user = User::create([
            'tenant_id' => $tenant->id, 'name' => 'Owner', 'email' => 'common@test.dev',
            'role' => 'owner', 'password' => Hash::make('password'),
        ]);

        $this->device = WhatsappInstance::create([
            'tenant_id' => $tenant->id, 'name' => 'Line',
            'instance_name' => 'common-dev', 'status' => 'open',
        ]);
    }

    public function test_settings_save_the_common_spintax_fields(): void
    {
        $this->actingAs($this->user)->put('/settings', [
            'bulk_greeting'       => '{Hi|Dear} {{name}},',
            'bulk_spintax_groups' => "Explore|Discover\nBook a demo|Request a demo",
        ])->assertRedirect();

        $settings = $this->user->tenant->fresh()->settings;
        $this->assertSame('{Hi|Dear} {{name}},', $settings['bulk_greeting']);
        $this->assertSame("Explore|Discover\nBook a demo|Request a demo", $settings['bulk_spintax_groups']);
    }

    public function test_synonym_groups_keep_capitalisation_and_never_touch_urls(): void
    {
        $settings = ['bulk_spintax' => false, 'bulk_spintax_groups' => 'trial|demo'];

        $out = Personalizer::applySynonyms('Demo a demo at https://x.co/demo today', $settings);

        // Prose occurrences swap to the first option (capitalisation kept);
        // the link is byte-identical.
        $this->assertSame('Trial a trial at https://x.co/demo today', $out);
    }

    public function test_campaign_variant_gets_common_opening_and_synonyms(): void
    {
        $contact = Contact::create([
            'tenant_id' => $this->user->tenant_id, 'name' => 'Zed',
            'phone' => '971500000021', 'wa_status' => 'valid',
        ]);

        $campaign = Campaign::create([
            'tenant_id' => $this->user->tenant_id,
            'whatsapp_instance_id' => $this->device->id, 'name' => 'CS', 'type' => 'text',
            'body' => 'Explore RISPER.', 'variants' => ['Explore more with us.'],
            'status' => 'sending', 'total' => 1,
        ]);

        // Slot 1 forces the VARIANT (the text with no greeting and no braces) —
        // the exact case where common spintax must still apply.
        $recipient = $campaign->recipients()->create([
            'tenant_id' => $this->user->tenant_id,
            'contact_id' => $contact->id, 'phone' => $contact->phone,
            'status' => 'pending', 'variant_index' => 1,
        ]);

        (new SendCampaignMessage($recipient->id))->handle();

        // Greeting topped the variant ({Hi|Hello} → Hi, {{name}} → Zed) and the
        // group swapped Explore → Check, deterministically.
        Http::assertSent(fn ($req) => str_contains($req->url(), $this->gatewaySendUrl('common-dev', 'send-text'))
            && $req['text'] === "Hi Zed,\n\nCheck more with us.");

        $this->assertSame('sent', $recipient->fresh()->status);
    }

    public function test_campaign_test_send_previews_opening_and_synonyms(): void
    {
        $this->actingAs($this->user);

        $campaign = Campaign::create([
            'whatsapp_instance_id' => $this->device->id, 'name' => 'CS2', 'type' => 'text',
            'body' => 'Book a demo today.', 'status' => 'draft', 'total' => 0,
        ]);

        $this->post(route('campaigns.test', $campaign), ['phone' => '971500000022'])
            ->assertSessionHas('success');

        // No contact for a test number → "there"; group Book a demo → first option.
        Http::assertSent(fn ($req) => str_contains($req->url(), '/messages/send-text')
            && $req['text'] === "Hi there,\n\nBook a demo today.");
    }

    public function test_single_message_applies_synonym_groups(): void
    {
        $this->actingAs($this->user)->post(route('single-message.send'), [
            'whatsapp_instance_id' => $this->device->id,
            'phone' => '971500000023',
            'body'  => 'Explore it now.',
        ])->assertSessionHas('success');

        // Single sends get the wording groups (no campaign opening — that is
        // campaign-only by design).
        Http::assertSent(fn ($req) => str_contains($req->url(), '/messages/send-text')
            && $req['text'] === 'Check it now.');
    }
}
