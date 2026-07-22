<?php

namespace App\Services;

use App\Models\Contact;
use App\Models\Message;
use App\Models\Sequence;
use App\Models\SequenceEnrollment;
use App\Models\Suppression;
use App\Models\WhatsappInstance;
use App\Support\Personalizer;
use App\Support\SendingWindow;
use App\Support\Tenancy;
use App\Support\Whatsapp;
use Illuminate\Support\Facades\DB;

/**
 * Drip / follow-up sequences: an ordered set of scheduled messages that each
 * enrolled contact receives with a delay between steps.
 */
class SequenceService
{
    /**
     * Create or update a sequence and (re)build its steps.
     *
     * @param  array<string, mixed>  $data
     */
    public function save(array $data, ?Sequence $sequence = null): Sequence
    {
        return DB::transaction(function () use ($data, $sequence) {
            $sequence ??= new Sequence;
            $sequence->fill([
                'name'                 => $data['name'],
                'whatsapp_instance_id' => $data['whatsapp_instance_id'] ?? null,
                'is_active'            => ! empty($data['is_active']),
            ])->save();

            $sequence->steps()->delete();

            foreach (array_values($data['steps'] ?? []) as $i => $step) {
                if (empty($step['body']) && empty($step['template_id'])) {
                    continue;
                }

                $sequence->steps()->create([
                    'position'      => $i,
                    'delay_minutes' => max(0, (int) ($step['delay_minutes'] ?? 0)),
                    'template_id'   => $step['template_id'] ?? null,
                    'body'          => $step['body'] ?? null,
                ]);
            }

            return $sequence;
        });
    }

    /**
     * Enroll contacts (optionally limited to a group) into the sequence.
     * Skips unverified, opted-out / suppressed / already-enrolled contacts. Returns the count.
     */
    public function enroll(Sequence $sequence, ?int $groupId = null): int
    {
        $firstDelay = (int) ($sequence->steps()->first()?->delay_minutes ?? 0);
        $existing = $sequence->enrollments()->pluck('contact_id')->all();
        $suppressed = Suppression::pluck('phone')->all();

        $query = Contact::query()->reachable()->whatsappValid()
            ->when(! empty($existing), fn ($q) => $q->whereNotIn('id', $existing))
            ->when(! empty($suppressed), fn ($q) => $q->whereNotIn('phone', $suppressed))
            ->when($groupId, fn ($q) => $q->whereHas('groups', fn ($g) => $g->where('contact_groups.id', $groupId)));

        $count = 0;

        $query->select('id')->chunkById(500, function ($contacts) use ($sequence, $firstDelay, &$count) {
            foreach ($contacts as $contact) {
                $sequence->enrollments()->create([
                    'contact_id'   => $contact->id,
                    'current_step' => 0,
                    'status'       => 'active',
                    'next_run_at'  => now()->addMinutes($firstDelay),
                ]);
                $count++;
            }
        });

        return $count;
    }

    /**
     * Process all enrollments that are due, sending one step each. Called by the
     * scheduler (sequences:dispatch). Runs across tenants.
     */
    public function dispatchDue(int $limit = 200): int
    {
        $due = SequenceEnrollment::withoutGlobalScopes()
            ->where('status', 'active')
            ->whereNotNull('next_run_at')
            ->where('next_run_at', '<=', now())
            ->limit($limit)
            ->get();

        $sent = 0;

        foreach ($due as $enrollment) {
            Tenancy::run($enrollment->tenant_id, function () use ($enrollment, &$sent) {
                if ($this->processOne($enrollment)) {
                    $sent++;
                }
            });
        }

        return $sent;
    }

    private function processOne(SequenceEnrollment $enrollment): bool
    {
        $sequence = Sequence::find($enrollment->sequence_id);
        $contact = Contact::find($enrollment->contact_id);

        if (! $sequence || ! $sequence->is_active || ! $contact) {
            $enrollment->update(['status' => 'stopped', 'next_run_at' => null]);

            return false;
        }

        // Respect opt-outs, WhatsApp verification and the do-not-contact list.
        if ($contact->opted_out || $contact->wa_status !== 'valid' || Suppression::has($contact->phone)) {
            $enrollment->update(['status' => 'stopped', 'next_run_at' => null]);

            return false;
        }

        // Quiet hours — push the due time forward, try again later.
        if ($next = SendingWindow::nextAllowed($sequence->tenant?->settings)) {
            $enrollment->update(['next_run_at' => $next]);

            return false;
        }

        $steps = $sequence->steps()->get();
        $step = $steps[$enrollment->current_step] ?? null;

        if (! $step) {
            $enrollment->update(['status' => 'completed', 'next_run_at' => null]);

            return false;
        }

        $device = $sequence->instance && $sequence->instance->isConnected()
            ? $sequence->instance
            : WhatsappInstance::where('status', 'open')->first();

        if (! $device) {
            $enrollment->update(['next_run_at' => now()->addMinutes(30)]); // no connected device — retry soon

            return false;
        }

        $this->send($device, $contact, $step, (array) ($sequence->tenant?->settings ?? []));

        // Advance to the next step (or finish).
        $nextIndex = $enrollment->current_step + 1;
        $nextStep = $steps[$nextIndex] ?? null;

        $enrollment->update($nextStep
            ? ['current_step' => $nextIndex, 'next_run_at' => now()->addMinutes((int) $nextStep->delay_minutes)]
            : ['current_step' => $nextIndex, 'status' => 'completed', 'next_run_at' => null]);

        return true;
    }

    private function send(WhatsappInstance $device, Contact $contact, $step, array $settings = []): void
    {
        $engine = Whatsapp::forInstance($device);
        $template = $step->template;

        // Variant chooser: a template step with A/B variants sends one of them.
        $raw = $template
            ? Personalizer::pickVariant($template->body, $template->variants)
            : (string) ($step->body ?? '');

        $body = $this->personalize($raw, $contact, $settings);

        if ($template && $template->media_url) {
            $result = $engine->sendMedia($device->instance_name, $contact->phone, $template->media_type ?: 'image', $template->media_url, $body);
            $type = 'media';
        } else {
            $result = $engine->sendText($device->instance_name, $contact->phone, $body);
            $type = 'text';
        }

        Message::create([
            'whatsapp_instance_id' => $device->id,
            'contact_id'           => $contact->id,
            'direction'            => 'out',
            'phone'                => $contact->phone,
            'type'                 => $type,
            'body'                 => $body,
            'status'               => ($result['ok'] ?? false) ? 'sent' : 'failed',
            'message_id'           => $result['message_id'] ?? null,
        ]);
    }

    /**
     * Everything the old inline version resolved ({{name}}, {{phone}}, contact
     * attributes) plus spintax {a|b}, {{date}} and the prefixed random
     * reference ID — the same wording tools campaign sends apply.
     */
    private function personalize(string $body, Contact $contact, array $settings = []): string
    {
        return Personalizer::render($body, $contact, $contact->phone, $settings);
    }
}
