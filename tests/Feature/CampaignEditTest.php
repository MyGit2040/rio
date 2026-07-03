<?php

namespace Tests\Feature;

use App\Models\Campaign;
use App\Models\Contact;
use App\Models\Tenant;
use App\Models\TrackedLink;
use App\Models\User;
use App\Models\WhatsappInstance;
use App\Services\CampaignService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class CampaignEditTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private WhatsappInstance $deviceA;

    private WhatsappInstance $deviceB;

    private Campaign $campaign;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create(['name' => 'Acme', 'slug' => 'acme-edit']);
        $user = User::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Owner', 'email' => 'edit@x.dev',
            'role' => 'owner', 'password' => Hash::make('secret'),
        ]);
        $this->actingAs($user);

        $this->deviceA = WhatsappInstance::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Device A',
            'instance_name' => 'inst-a', 'token' => 'tok-a', 'status' => 'open',
        ]);
        $this->deviceB = WhatsappInstance::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Device B',
            'instance_name' => 'inst-b', 'token' => 'tok-b', 'status' => 'open',
        ]);

        foreach (range(1, 4) as $i) {
            Contact::create([
                'tenant_id' => $this->tenant->id, 'name' => "C{$i}",
                'phone' => '97152000000'.$i, 'opted_out' => false,
            ]);
        }

        $this->campaign = app(CampaignService::class)->create([
            'name' => 'Edit Me', 'body' => 'Original body', 'audience' => 'all',
            'min_delay' => 5, 'max_delay' => 15, 'schedule' => 'later',
            'scheduled_at' => now()->addDay()->toDateTimeString(),
            'device_ids' => [$this->deviceA->id],
        ]);
        $this->campaign->update(['status' => 'paused']);
    }

    /** Everything the edit form submits, with sensible defaults to override. */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'name'        => 'Edit Me',
            'device_ids'  => [$this->deviceA->id],
            'rotate_every' => 0,
            'body'        => 'Original body',
            'footer'      => null,
            'min_delay'   => 5,
            'max_delay'   => 15,
            'max_retries' => 3,
        ], $overrides);
    }

    public function test_edit_page_renders_for_a_paused_campaign(): void
    {
        $this->get(route('campaigns.edit', $this->campaign))
            ->assertOk()
            ->assertSee('Edit campaign')
            ->assertSee('Edit Me')
            ->assertSee('Device B');
    }

    public function test_pacing_caps_rotation_and_name_are_editable_while_paused(): void
    {
        $this->put(route('campaigns.update', $this->campaign), $this->payload([
            'name'          => 'Renamed run',
            'device_ids'    => [$this->deviceA->id, $this->deviceB->id],
            'device_limits' => [$this->deviceA->id => 100, $this->deviceB->id => ''],
            'rotate_every'  => 25,
            'min_delay'     => 40,
            'max_delay'     => 90,
            'max_retries'   => 5,
        ]))->assertRedirect(route('campaigns.show', $this->campaign))
            ->assertSessionHas('success', fn ($m) => str_contains($m, 'Resume'));

        $c = $this->campaign->fresh();
        $this->assertSame('Renamed run', $c->name);
        $this->assertEqualsCanonicalizing([$this->deviceA->id, $this->deviceB->id], $c->device_ids);
        $this->assertSame([$this->deviceA->id => 100], $c->device_limits);
        $this->assertSame(25, (int) $c->rotate_every);
        $this->assertSame(40, (int) $c->min_delay);
        $this->assertSame(90, (int) $c->max_delay);
        $this->assertSame(5, (int) $c->max_retries);
        $this->assertSame('paused', $c->status);
    }

    public function test_message_body_footer_and_variants_are_editable(): void
    {
        $this->put(route('campaigns.update', $this->campaign), $this->payload([
            'body'     => 'New body {{name}}',
            'footer'   => 'Regards, Acme',
            'variants' => ['Variant one', '', 'Variant two'],
        ]))->assertSessionHas('success');

        $c = $this->campaign->fresh();
        $this->assertSame('New body {{name}}', $c->body);
        $this->assertSame('Regards, Acme', $c->footer);
        $this->assertSame(['Variant one', 'Variant two'], $c->variants);
    }

    public function test_poll_question_and_options_are_editable(): void
    {
        $poll = app(CampaignService::class)->create([
            'name' => 'Poll run', 'audience' => 'all',
            'min_delay' => 1, 'max_delay' => 2, 'schedule' => 'later',
            'scheduled_at' => now()->addDay()->toDateTimeString(),
            'device_ids' => [$this->deviceA->id],
            'template_id' => \App\Models\Template::create([
                'tenant_id' => $this->tenant->id, 'name' => 'P', 'type' => 'poll',
                'body' => 'Vote!', 'poll' => ['question' => 'Old?', 'options' => ['A', 'B'], 'multiple' => false],
            ])->id,
        ]);
        $poll->update(['status' => 'paused']);

        $this->put(route('campaigns.update', $poll), $this->payload([
            'name'          => 'Poll run',
            'body'          => 'Vote now!',
            'poll_question' => 'Which service?',
            'poll_options'  => ['VAT filing', 'Bookkeeping', '', 'Payroll'],
            'poll_multiple' => 1,
        ]))->assertSessionHas('success');

        $this->assertSame(
            ['question' => 'Which service?', 'options' => ['VAT filing', 'Bookkeeping', 'Payroll'], 'multiple' => true],
            $poll->fresh()->poll
        );
    }

    public function test_a_poll_needs_at_least_two_options(): void
    {
        $poll = app(CampaignService::class)->create([
            'name' => 'Poll run 2', 'audience' => 'all',
            'min_delay' => 1, 'max_delay' => 2, 'schedule' => 'later',
            'scheduled_at' => now()->addDay()->toDateTimeString(),
            'device_ids' => [$this->deviceA->id],
            'template_id' => \App\Models\Template::create([
                'tenant_id' => $this->tenant->id, 'name' => 'P2', 'type' => 'poll',
                'body' => '', 'poll' => ['question' => 'Old?', 'options' => ['A', 'B'], 'multiple' => false],
            ])->id,
        ]);
        $poll->update(['status' => 'paused']);

        $this->put(route('campaigns.update', $poll), $this->payload([
            'poll_question' => 'Which?',
            'poll_options'  => ['Only one', ''],
        ]))->assertSessionHasErrors('poll_options');
    }

    public function test_editing_is_blocked_mid_send_and_after_completion(): void
    {
        $this->campaign->update(['status' => 'sending']);
        $this->get(route('campaigns.edit', $this->campaign))
            ->assertRedirect(route('campaigns.show', $this->campaign));
        $this->put(route('campaigns.update', $this->campaign), $this->payload(['name' => 'Nope']))
            ->assertSessionHas('error');

        $this->campaign->update(['status' => 'completed']);
        $this->put(route('campaigns.update', $this->campaign), $this->payload(['name' => 'Nope']))
            ->assertSessionHas('error');

        $this->assertSame('Edit Me', $this->campaign->fresh()->name);
    }

    public function test_scheduled_campaign_can_move_its_send_time(): void
    {
        $this->campaign->update(['status' => 'scheduled']);
        $newTime = now()->addDays(2)->startOfHour();

        $this->put(route('campaigns.update', $this->campaign), $this->payload([
            'scheduled_at' => $newTime->format('Y-m-d\TH:i'),
        ]))->assertSessionHas('success');

        $this->assertSame($newTime->toDateTimeString(), $this->campaign->fresh()->scheduled_at->toDateTimeString());
    }

    public function test_foreign_tenant_device_is_rejected_by_validation(): void
    {
        $other = Tenant::create(['name' => 'Other', 'slug' => 'other-edit']);
        $foreign = WhatsappInstance::create([
            'tenant_id' => $other->id, 'name' => 'Foreign',
            'instance_name' => 'inst-f', 'token' => 'tok-f', 'status' => 'open',
        ]);

        $this->put(route('campaigns.update', $this->campaign), $this->payload([
            'device_ids' => [$foreign->id],
        ]))->assertSessionHasErrors('device_ids.0');
    }

    public function test_editing_a_tracked_body_never_chain_wraps_existing_short_links(): void
    {
        $tracked = app(CampaignService::class)->create([
            'name' => 'Links', 'body' => 'Read https://example.com/offer today', 'audience' => 'all',
            'min_delay' => 1, 'max_delay' => 2, 'schedule' => 'later',
            'scheduled_at' => now()->addDay()->toDateTimeString(),
            'device_ids' => [$this->deviceA->id], 'track_links' => 1,
        ]);
        $tracked->update(['status' => 'paused']);
        $wrappedBody = $tracked->fresh()->body;
        $this->assertStringContainsString('/l/', $wrappedBody);

        // Re-save the body exactly as the form shows it (with the short link inside).
        $this->put(route('campaigns.update', $tracked), $this->payload([
            'name' => 'Links', 'body' => $wrappedBody.' — extended https://example.com/offer',
        ]))->assertSessionHas('success');

        // The original URL still has exactly ONE tracked link; no link-to-a-link chains.
        $links = TrackedLink::where('campaign_id', $tracked->id)->get();
        $this->assertCount(1, $links->where('url', 'https://example.com/offer'));
        $this->assertSame(0, $links->filter(fn ($l) => str_contains($l->url, '/l/'))->count());
    }
}
