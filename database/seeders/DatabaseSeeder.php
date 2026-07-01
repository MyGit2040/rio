<?php

namespace Database\Seeders;

use App\Models\ChatbotRule;
use App\Models\Contact;
use App\Models\ContactGroup;
use App\Models\Template;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $tenant = Tenant::create([
            'name' => 'Demo Workspace',
            'slug' => 'demo',
            'plan' => 'pro',
        ]);

        User::create([
            'tenant_id' => $tenant->id,
            'name'      => 'Demo Admin',
            'email'     => 'demo@eagle.test',
            'role'      => 'owner',
            'password'  => Hash::make('password'),
        ]);

        $tid = $tenant->id;

        // Groups
        $vip = ContactGroup::create(['tenant_id' => $tid, 'name' => 'VIP customers', 'color' => '#9333ea']);
        $news = ContactGroup::create(['tenant_id' => $tid, 'name' => 'Newsletter', 'color' => '#2563eb']);

        // Contacts (use real-looking but clearly demo numbers)
        $samples = [
            ['Aisha Khan', '971501234001', [$vip->id]],
            ['Ravi Patel', '971501234002', [$vip->id, $news->id]],
            ['Sara Ahmed', '971501234003', [$news->id]],
            ['John Mensah', '971501234004', []],
            ['Lena Müller', '971501234005', [$news->id]],
        ];

        foreach ($samples as [$name, $phone, $groupIds]) {
            $contact = Contact::create([
                'tenant_id' => $tid,
                'name'      => $name,
                'phone'     => $phone,
            ]);
            $contact->groups()->sync($groupIds);
        }

        // Templates
        Template::create([
            'tenant_id' => $tid,
            'name'      => 'Welcome message',
            'type'      => 'text',
            'body'      => "Hi {{name}}, thanks for joining us! Reply HELP anytime.",
        ]);

        Template::create([
            'tenant_id' => $tid,
            'name'      => 'Feedback poll',
            'type'      => 'poll',
            'poll'      => [
                'question' => 'How was your experience with us?',
                'options'  => ['Excellent', 'Good', 'Could be better'],
                'multiple' => false,
            ],
        ]);

        // Chatbot rule
        ChatbotRule::create([
            'tenant_id'  => $tid,
            'name'       => 'Greeting auto-reply',
            'match_type' => 'contains',
            'keywords'   => 'hi, hello, hey',
            'reply'      => "Hello! 👋 Thanks for reaching out. How can we help you today?",
            'is_active'  => true,
            'priority'   => 10,
        ]);
    }
}
