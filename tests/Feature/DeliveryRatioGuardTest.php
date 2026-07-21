<?php

namespace Tests\Feature;

use App\Models\Campaign;
use App\Models\CampaignRecipient;
use App\Models\Tenant;
use App\Models\WhatsappInstance;
use App\Services\DeliveryRatioGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The double-tick delivery-ratio guard: a number whose recent delivered rate
 * collapses is cooled down; a healthy number is left alone; a partial window
 * never judges.
 */
class DeliveryRatioGuardTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private WhatsappInstance $device;

    private Campaign $campaign;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create(['name' => 'Acme', 'slug' => 'acme-dr']);
        $this->device = WhatsappInstance::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Device 1',
            'instance_name' => 'inst-1', 'token' => 'tok-1', 'status' => 'open',
        ]);
        $this->campaign = Campaign::create([
            'tenant_id' => $this->tenant->id, 'whatsapp_instance_id' => $this->device->id,
            'name' => 'Blast', 'type' => 'text', 'body' => 'Hi', 'audience' => 'all',
            'min_delay' => 1, 'max_delay' => 2, 'status' => 'sending',
        ]);
    }

    /**
     * Seed $count recipients for the device, $delivered of them double-ticked,
     * all sent long enough ago to be "mature".
     */
    private function seedRecipients(int $count, int $delivered): void
    {
        for ($i = 0; $i < $count; $i++) {
            CampaignRecipient::create([
                'tenant_id' => $this->tenant->id,
                'campaign_id' => $this->campaign->id,
                'whatsapp_instance_id' => $this->device->id,
                'phone' => '9715000000'.str_pad((string) $i, 2, '0', STR_PAD_LEFT),
                'status' => $i < $delivered ? 'delivered' : 'sent',
                'sent_at' => now()->subMinutes(30),
            ]);
        }
    }

    public function test_a_collapsed_delivery_ratio_triggers_a_cooldown(): void
    {
        // 20/50 delivered = 40%, below the 60% threshold.
        $this->seedRecipients(50, 20);

        $guard = app(DeliveryRatioGuard::class);
        $this->assertTrue($guard->evaluate($this->device));
        $this->assertTrue($guard->inCooldown($this->device->id));
        // An operator alert is raised (the device id lives in the context JSON).
        $this->assertDatabaseHas('alerts', ['tenant_id' => $this->tenant->id, 'level' => 'error']);
    }

    public function test_a_healthy_delivery_ratio_does_not_cool_down(): void
    {
        // 45/50 delivered = 90%.
        $this->seedRecipients(50, 45);

        $guard = app(DeliveryRatioGuard::class);
        $this->assertFalse($guard->evaluate($this->device));
        $this->assertFalse($guard->inCooldown($this->device->id));
    }

    public function test_a_partial_window_is_never_judged(): void
    {
        // Only 20 messages so far, all undelivered — but too few to judge.
        $this->seedRecipients(20, 0);

        $guard = app(DeliveryRatioGuard::class);
        $this->assertFalse($guard->evaluate($this->device));
        $this->assertFalse($guard->inCooldown($this->device->id));
    }

    public function test_fresh_messages_within_the_grace_period_are_excluded(): void
    {
        // A full window, all undelivered, but all sent seconds ago — too fresh to
        // count, so the guard must not judge them.
        for ($i = 0; $i < 50; $i++) {
            CampaignRecipient::create([
                'tenant_id' => $this->tenant->id,
                'campaign_id' => $this->campaign->id,
                'whatsapp_instance_id' => $this->device->id,
                'phone' => '9715000000'.str_pad((string) $i, 2, '0', STR_PAD_LEFT),
                'status' => 'sent',
                'sent_at' => now()->subMinutes(2),
            ]);
        }

        $guard = app(DeliveryRatioGuard::class);
        $this->assertFalse($guard->evaluate($this->device));
        $this->assertFalse($guard->inCooldown($this->device->id));
    }

    public function test_an_active_cooldown_is_not_re_evaluated(): void
    {
        $this->seedRecipients(50, 20);
        $guard = app(DeliveryRatioGuard::class);

        $this->assertTrue($guard->evaluate($this->device));   // trips
        $this->assertFalse($guard->evaluate($this->device));  // already cooling down → no re-trigger
    }
}
