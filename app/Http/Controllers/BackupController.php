<?php

namespace App\Http\Controllers;

use App\Models\ChatbotRule;
use App\Models\Contact;
use App\Models\ContactGroup;
use App\Models\Template;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;

class BackupController extends Controller
{
    private function guard(): void
    {
        abort_unless(auth()->user()->isOwner(), 403);
    }

    public function index()
    {
        $this->guard();

        return view('backup.index');
    }

    /**
     * Build an unencrypted ZIP of the workspace's reusable data and stream it down.
     */
    public function create(): StreamedResponse
    {
        $this->guard();

        $tenant = auth()->user()->tenant;

        $payload = [
            'version'      => 1,
            'exported_at'  => now()->toIso8601String(),
            'workspace'    => $tenant->name,
            'groups'       => ContactGroup::get(['name', 'color'])->toArray(),
            'contacts'     => Contact::with('groups:id,name')->get()->map(fn ($c) => [
                'name' => $c->name, 'phone' => $c->phone, 'email' => $c->email,
                'country' => $c->country, 'opted_out' => $c->opted_out,
                'marketing_opted_in' => $c->marketing_opted_in,
                'marketing_opted_in_at' => $c->marketing_opted_in_at?->toIso8601String(),
                'marketing_consent_source' => $c->marketing_consent_source,
                'wa_status' => $c->wa_status,
                'groups' => $c->groups->pluck('name')->all(),
            ])->all(),
            'templates'    => Template::get()->map->only([
                'name', 'type', 'body', 'variants', 'media_url', 'media_type', 'poll', 'buttons', 'cards',
            ])->all(),
            'chatbot_rules' => ChatbotRule::get()->map->only([
                'name', 'match_type', 'keywords', 'reply', 'use_ai', 'is_active', 'priority',
            ])->all(),
        ];

        $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        $filename = 'eagle-backup-'.str($tenant->slug)->slug().'-'.now()->format('Y-m-d').'.zip';

        return response()->streamDownload(function () use ($json) {
            $tmp = tempnam(sys_get_temp_dir(), 'bkp');
            $zip = new \ZipArchive;
            $zip->open($tmp, \ZipArchive::OVERWRITE);
            $zip->addFromString('backup.json', $json);
            $zip->close();
            echo file_get_contents($tmp);
            @unlink($tmp);
        }, $filename, ['Content-Type' => 'application/zip']);
    }

    /**
     * Restore a backup ZIP/JSON into the current workspace (merges — never deletes).
     */
    public function restore(Request $request): RedirectResponse
    {
        $this->guard();

        $request->validate([
            'file' => ['required', 'file', 'mimes:zip,json', 'max:10240'],
        ]);

        $data = $this->readBackup($request->file('file')->getRealPath());

        if (! $data || ($data['version'] ?? null) !== 1) {
            return back()->with('error', 'That does not look like an Eagle backup file.');
        }

        $tenantId = auth()->user()->tenant_id;
        $summary = ['groups' => 0, 'contacts' => 0, 'templates' => 0, 'rules' => 0];

        DB::transaction(function () use ($data, $tenantId, &$summary) {
            $groupIds = [];
            foreach ($data['groups'] ?? [] as $g) {
                $group = ContactGroup::firstOrCreate(['name' => $g['name']], ['color' => $g['color'] ?? '#16a34a']);
                $groupIds[$g['name']] = $group->id;
                $summary['groups']++;
            }

            foreach ($data['contacts'] ?? [] as $c) {
                if (empty($c['phone'])) {
                    continue;
                }
                $contact = Contact::updateOrCreate(
                    ['phone' => preg_replace('/\D+/', '', (string) $c['phone'])],
                    ['name' => $c['name'] ?? null, 'email' => $c['email'] ?? null,
                        'country' => $c['country'] ?? null, 'opted_out' => (bool) ($c['opted_out'] ?? false),
                        'marketing_opted_in' => (bool) ($c['marketing_opted_in'] ?? false),
                        'marketing_opted_in_at' => $c['marketing_opted_in_at'] ?? null,
                        'marketing_consent_source' => $c['marketing_consent_source'] ?? null,
                        'wa_status' => $c['wa_status'] ?? 'unverified'],
                );
                $ids = collect($c['groups'] ?? [])->map(fn ($n) => $groupIds[$n] ?? null)->filter()->all();
                $contact->groups()->syncWithoutDetaching($ids);
                $summary['contacts']++;
            }

            foreach ($data['templates'] ?? [] as $t) {
                Template::create(array_merge(['tenant_id' => $tenantId], $t));
                $summary['templates']++;
            }

            foreach ($data['chatbot_rules'] ?? [] as $r) {
                ChatbotRule::create(array_merge(['tenant_id' => $tenantId], $r));
                $summary['rules']++;
            }
        });

        return back()->with('success',
            "Restored: {$summary['contacts']} contacts, {$summary['groups']} groups, {$summary['templates']} templates, {$summary['rules']} rules.");
    }

    /**
     * @return array<string, mixed>|null
     */
    private function readBackup(string $path): ?array
    {
        // JSON uploaded directly.
        $head = file_get_contents($path, false, null, 0, 1) ?: '';
        if ($head === '{') {
            return json_decode((string) file_get_contents($path), true);
        }

        // ZIP containing backup.json.
        $zip = new \ZipArchive;
        if ($zip->open($path) === true) {
            $json = $zip->getFromName('backup.json');
            $zip->close();

            return $json ? json_decode($json, true) : null;
        }

        return null;
    }
}
