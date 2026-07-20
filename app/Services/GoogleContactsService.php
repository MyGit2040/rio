<?php

namespace App\Services;

use App\Models\Contact;
use App\Models\GoogleContactLink;
use App\Models\Tenant;
use App\Models\WhatsappInstance;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class GoogleContactsService
{
    public function authorizationUrl(Tenant $tenant, string $state, string $callback): string
    {
        $clientId = (string) data_get($tenant->settings, 'google_contacts_client_id');
        if ($clientId === '') {
            throw new RuntimeException('Add the Google OAuth Client ID first.');
        }

        return 'https://accounts.google.com/o/oauth2/v2/auth?'.http_build_query([
            'client_id' => $clientId, 'redirect_uri' => $callback, 'response_type' => 'code',
            'scope' => 'https://www.googleapis.com/auth/contacts https://www.googleapis.com/auth/userinfo.email',
            'access_type' => 'offline', 'prompt' => 'consent', 'state' => $state,
        ]);
    }

    /** @return array{email: ?string} */
    public function connect(Tenant $tenant, WhatsappInstance $device, string $code, string $callback): array
    {
        $token = Http::asForm()->timeout(20)->post('https://oauth2.googleapis.com/token', [
            'code' => $code, 'client_id' => data_get($tenant->settings, 'google_contacts_client_id'),
            'client_secret' => $this->clientSecret($tenant), 'redirect_uri' => $callback,
            'grant_type' => 'authorization_code',
        ])->throw()->json();

        if (empty($token['refresh_token'])) {
            throw new RuntimeException('Google did not return a refresh token. Disconnect this Google account in Google settings and connect it again.');
        }

        $email = Http::withToken($token['access_token'])->timeout(15)
            ->get('https://www.googleapis.com/oauth2/v3/userinfo')->json('email');
        $device->update([
            'google_contacts_email' => is_string($email) ? $email : $device->google_contacts_email,
            'google_contacts_token' => Crypt::encryptString(json_encode(['refresh_token' => $token['refresh_token']])),
            'google_contacts_connected_at' => now(),
        ]);

        return ['email' => is_string($email) ? $email : null];
    }

    /** @param array<int, Contact> $contacts @return array{created:int, skipped:int, failed:int} */
    public function sync(WhatsappInstance $device, array $contacts): array
    {
        $accessToken = $this->accessToken($device);
        $counts = ['created' => 0, 'skipped' => 0, 'failed' => 0];

        foreach ($contacts as $contact) {
            if (GoogleContactLink::where('whatsapp_instance_id', $device->id)->where('contact_id', $contact->id)->exists()) {
                $counts['skipped']++;
                continue;
            }
            $payload = ['names' => [['givenName' => $contact->name ?: $contact->phone]], 'phoneNumbers' => [['value' => '+'.$contact->phone]]];
            if ($contact->email) {
                $payload['emailAddresses'] = [['value' => $contact->email]];
            }
            try {
                $person = Http::withToken($accessToken)->timeout(20)
                    ->post('https://people.googleapis.com/v1/people:createContact', $payload)->throw()->json();
                GoogleContactLink::create([
                    'tenant_id' => $device->tenant_id, 'whatsapp_instance_id' => $device->id,
                    'contact_id' => $contact->id, 'resource_name' => $person['resourceName'],
                ]);
                $counts['created']++;
            } catch (\Throwable) {
                $counts['failed']++;
            }
        }

        return $counts;
    }

    private function accessToken(WhatsappInstance $device): string
    {
        if (! $device->google_contacts_token) {
            throw new RuntimeException("Connect Google for {$device->name} first.");
        }
        $token = json_decode(Crypt::decryptString($device->google_contacts_token), true);
        $tenant = $device->tenant;
        $response = Http::asForm()->timeout(20)->post('https://oauth2.googleapis.com/token', [
            'client_id' => data_get($tenant->settings, 'google_contacts_client_id'),
            'client_secret' => $this->clientSecret($tenant), 'refresh_token' => $token['refresh_token'] ?? '', 'grant_type' => 'refresh_token',
        ])->throw()->json();

        return (string) $response['access_token'];
    }

    private function clientSecret(Tenant $tenant): string
    {
        $secret = (string) data_get($tenant->settings, 'google_contacts_client_secret');
        return $secret !== '' ? Crypt::decryptString($secret) : '';
    }
}
