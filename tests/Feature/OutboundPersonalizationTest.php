<?php

namespace Tests\Feature;

use App\Models\Campaign;
use App\Models\Contact;
use App\Models\Message;
use App\Models\Sequence;
use App\Models\Template;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WhatsappInstance;
use App\Services\SequenceService;
use App\Support\Personalizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Every send surface — not only campaign jobs — must apply the wording tools:
 * spintax {a|b}, the message-variant chooser and the prefixed random reference
 * ID. These tests read the ACTUAL text delivered to the faked gateway, so any
 * surface that silently stops personalizing fails here.
 *
 * The workspace is configured with bulk_spintax=false (spin resolves to the
 * FIRST option, deterministically) and bulk_random_prefix 'EG-' so every
 * assertion can pin the exact expected text around the EG-\d{6} reference.
 */
class OutboundPersonalizationTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private WhatsappInstance $device;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fakeGateway();

        $tenant = Tenant::create([
            'name'     => 'Acme',
            'slug'     => 'acme-personalize',
            'settings' => ['bulk_spintax' => false, 'bulk_random_prefix' => 'EG-'],
        ]);

        $this->user = User::create([
            'tenant_id' => $tenant->id,
            'name'      => 'Owner',
            'email'     => 'p13n@test.dev',
            'role'      => 'owner',
            'password'  => Hash::make('password'),
        ]);

        $this->device = WhatsappInstance::create([
            'tenant_id' => $tenant->id, 'name' => 'Line',
            'instance_name' => 'p13n-dev', 'status' => 'open',
        ]);
    }

    /** Assert the gateway received a send-text whose body matches $pattern. */
    private function assertSentTextMatches(string $pattern): void
    {
        Http::assertSent(fn ($req) => str_contains($req->url(), '/messages/send-text')
            && preg_match($pattern, (string) $req['text']) === 1);
    }

    public function test_chat_send_applies_spintax_merge_tags_and_reference_id(): void
    {
        Contact::create([
            'tenant_id'  => $this->user->tenant_id,
            'name'       => 'Zed',
            'phone'      => '971500000009',
            'attributes' => ['company' => 'Acme Rockets'],
        ]);

        $this->actingAs($this->user)
            ->postJson(route('chats.send', $this->device), [
                'phone' => '971500000009',
                'body'  => '{Hi|Hello} {{name}} of {{company}} ref {{ref_id}}',
            ])
            ->assertOk();

        $pattern = '/^Hi Zed of Acme Rockets ref EG-\d{6}$/';
        $this->assertSentTextMatches($pattern);

        // The thread stores what was actually sent, not the raw template.
        $this->assertMatchesRegularExpression($pattern, (string) Message::first()->body);
    }

    public function test_single_message_applies_spintax_and_reference_alias(): void
    {
        $this->actingAs($this->user)
            ->post(route('single-message.send'), [
                'whatsapp_instance_id' => $this->device->id,
                'phone'                => '971500000010',
                'body'                 => '{One|Two} for {{name}} ref [random]',
            ])
            ->assertSessionHas('success');

        // Unknown number → {{name}} falls back to "there"; [random] alias resolves.
        $this->assertSentTextMatches('/^One for there ref EG-\d{6}$/');
    }

    public function test_single_message_rotates_template_variants(): void
    {
        $template = Template::create([
            'tenant_id' => $this->user->tenant_id, 'name' => 'T', 'type' => 'text',
            'body' => 'Alpha', 'variants' => ['Bravo', 'Charlie'],
        ]);

        $this->actingAs($this->user)
            ->post(route('single-message.send'), [
                'whatsapp_instance_id' => $this->device->id,
                'phone'                => '971500000011',
                'template_id'          => $template->id,
            ])
            ->assertSessionHas('success');

        Http::assertSent(fn ($req) => str_contains($req->url(), '/messages/send-text')
            && in_array($req['text'], ['Alpha', 'Bravo', 'Charlie'], true));
    }

    public function test_campaign_test_send_previews_variants_footer_spintax_and_reference(): void
    {
        $this->actingAs($this->user);

        $campaign = Campaign::create([
            'whatsapp_instance_id' => $this->device->id, 'name' => 'C', 'type' => 'text',
            'body' => '{Deal|Offer} {{ref_id}}', 'footer' => 'Reply STOP to opt out',
            'status' => 'draft', 'total' => 0,
        ]);

        $this->post(route('campaigns.test', $campaign), ['phone' => '971500000012'])
            ->assertSessionHas('success');

        Http::assertSent(fn ($req) => str_contains($req->url(), '/messages/send-text')
            && preg_match('/^Deal EG-\d{6}/', (string) $req['text']) === 1
            && str_ends_with((string) $req['text'], "\n\nReply STOP to opt out"));
    }

    public function test_sequence_step_applies_spintax_merge_tags_and_reference_id(): void
    {
        $this->actingAs($this->user);

        Contact::create(['name' => 'Zed', 'phone' => '971500000013', 'wa_status' => 'valid']);

        $this->post('/sequences', [
            'name' => 'Onboarding', 'is_active' => '1',
            'whatsapp_instance_id' => $this->device->id,
            'steps' => [['delay_value' => 0, 'delay_unit' => 'minutes', 'body' => '{Hey|Yo} {{name}} ref {{ref_id}}']],
        ])->assertRedirect();

        $sequence = Sequence::first();
        $this->post("/sequences/{$sequence->id}/enroll", [])->assertRedirect();

        $sequence->enrollments()->update(['next_run_at' => now()->subMinute()]);
        app(SequenceService::class)->dispatchDue();

        $this->assertSentTextMatches('/^Hey Zed ref EG-\d{6}$/');
    }

    public function test_inbox_reply_applies_spintax_and_merge_tags(): void
    {
        $contact = Contact::create([
            'tenant_id' => $this->user->tenant_id, 'name' => 'Zed', 'phone' => '971500000014',
        ]);

        $this->actingAs($this->user)
            ->post(route('inbox.reply', $contact), ['body' => '{Hi|Hello} {{name}} ref {{ref_id}}'])
            ->assertSessionHas('success');

        $this->assertSentTextMatches('/^Hi Zed ref EG-\d{6}$/');
    }

    public function test_personalizer_variant_pool_and_unknown_tokens(): void
    {
        // No variants → the main body; a pool pick always comes from the pool.
        $this->assertSame('Solo', Personalizer::pickVariant('Solo', null));
        $this->assertContains(Personalizer::pickVariant('A', ['B', 'C']), ['A', 'B', 'C']);

        // Random spin (default) picks only listed options; unknown {{tokens}} go blank.
        $out = Personalizer::render('{X|Y} {{unknown}} end', null, '971500000000', []);
        $this->assertMatchesRegularExpression('/^(X|Y)  end$/', $out);
    }
}
