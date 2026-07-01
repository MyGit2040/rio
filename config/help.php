<?php

/*
 | Help Center content. Curated, plain-English guides per feature.
 | Each article: title, icon, summary, steps[], example, tips[].
 | Rendered by HelpController + resources/views/help/*.
 */

return [

    'getting-started' => [
        'title'   => 'Getting started',
        'icon'    => 'grid',
        'summary' => 'Connect a number, add contacts, and send your first message.',
        'steps'   => [
            'Connect WhatsApp: Devices → Add a device → scan the QR (or link with a code).',
            'Add people: Contacts → Import CSV (download the sample first) or Add contact.',
            'Write a message: Templates → New template.',
            'Send: Bulk messages → New campaign → pick your number, who to send to, and your message → Launch.',
        ],
        'example' => 'A salon connects one number, imports 200 clients, and sends a "20% off this week" message to everyone tagged "regular".',
        'tips'    => [
            'Only message people who agreed to hear from you.',
            'Go slow on a brand-new number (see Number health → warm-up).',
        ],
    ],

    'devices' => [
        'title'   => 'Connecting a WhatsApp number',
        'icon'    => 'device',
        'summary' => 'Link one or more WhatsApp numbers to send from.',
        'steps'   => [
            'Devices → Add a device → give it a name (e.g. "Sales line").',
            'Scan the QR with WhatsApp → Settings → Linked devices → Link a device.',
            'No camera? Type the number, click "Get code", then on the phone use "Link with phone number" and enter the 8-digit code.',
            'When it turns green (Connected) you are ready to send.',
        ],
        'example' => 'You link your business number "Sales line" and a second number "Support line" so you can send from either.',
        'tips'    => [
            'Keep the phone online — the linked number sends through it.',
            'Set a Daily send cap on each number to stay safe.',
        ],
    ],

    'contacts' => [
        'title'   => 'Contacts, import & tags',
        'icon'    => 'users',
        'summary' => 'Build your audience and organise it with groups and tags.',
        'steps'   => [
            'Import: Contacts → Import CSV → Download the sample (columns: name, number).',
            'Put the full country code in the number (digits only, e.g. 971501234567).',
            'Duplicates are skipped automatically.',
            'Tag people (e.g. vip, lead) so you can message just that group later.',
            'Export any filtered list with the Export button.',
        ],
        'example' => 'You import a "number" column with country codes, tag 50 people "vip", then send only to the vip tag.',
        'tips'    => [
            'Use Verify WhatsApp to check which numbers are really on WhatsApp.',
            'Anyone who replies "STOP/UNSUBSCRIBE" is opted out automatically.',
        ],
    ],

    'templates' => [
        'title'   => 'Message templates',
        'icon'    => 'doc',
        'summary' => 'Reusable messages — text, image, poll, buttons or carousel.',
        'steps'   => [
            'Templates → New template → pick a type.',
            'Use the toolbar to insert {{name}}, {{phone}}, {{date}} and greetings.',
            'Add an image/video/file with Upload — the live preview shows how it looks.',
            'Add message variants so each send uses slightly different wording.',
            'Watch the Spam score as you type — red words hurt delivery.',
        ],
        'example' => 'A template: "{Hi|Dear} {{name}}, your order is ready!" with a photo attached.',
        'tips'    => [
            'Personalise with {{name}} — it lowers the spam score.',
            'Preview a saved template with the eye icon in the list.',
        ],
    ],

    'campaigns' => [
        'title'   => 'Bulk campaigns',
        'icon'    => 'send',
        'summary' => 'Send a message to many contacts, safely and paced.',
        'steps'   => [
            'Bulk messages → New campaign → name it, pick the number(s).',
            'Choose who: all contacts, a group, or a tag.',
            'Write the message or pick a template.',
            'Set the delay between messages, then Launch (or Schedule for later).',
            'Watch progress live; Retry failed or Export results afterwards.',
        ],
        'example' => 'You send a template to the "vip" tag from 2 numbers, rotating to the next number every 50 messages.',
        'tips'    => [
            'Bigger delays = safer sending. Start slow.',
            'Turn on "Rotate after every N" when using multiple numbers.',
            'Message variants rotate on every message to keep copy fresh.',
        ],
    ],

    'sequences' => [
        'title'   => 'Drip / follow-up sequences',
        'icon'    => 'drip',
        'summary' => 'Send a series of messages automatically over days.',
        'steps'   => [
            'Drip sequences → New sequence → add steps.',
            'Each step has a delay (e.g. wait 1 day) and a message or template.',
            'Open the sequence → Enroll contacts (all, or a group).',
            'The system sends each step on time; opted-out people are skipped.',
        ],
        'example' => 'Day 0: welcome. Day 2: how-to tips. Day 5: special offer — all automatic.',
        'tips'    => [
            'Keep it helpful, not pushy.',
            'The scheduler must be running on the server (cron) for steps to go out.',
        ],
    ],

    'inbox' => [
        'title'   => 'Inbox (two-way chat)',
        'icon'    => 'inbox',
        'summary' => 'See replies and answer them like a normal chat.',
        'steps'   => [
            'Inbox → click a conversation to open the thread.',
            'Type a reply and send — it goes from your connected number.',
            'Open a contact\'s profile to see their full history.',
        ],
        'example' => 'A customer replies "Is it available?" — you answer right from the Inbox.',
        'tips'    => [
            'Set a "Hook number" in Settings to also forward replies to your phone.',
        ],
    ],

    'chatbot' => [
        'title'   => 'Auto reply',
        'icon'    => 'bot',
        'summary' => 'Reply automatically when a message contains certain words.',
        'steps'   => [
            'Auto reply → New rule → set the keywords (e.g. "price, cost").',
            'Write the reply that should be sent back.',
            'Rules run top to bottom by priority — the first match wins.',
        ],
        'example' => 'Keyword "hours" → auto-reply "We\'re open 9am–9pm daily."',
        'tips'    => [
            'Keep replies short and helpful.',
        ],
    ],

    'compliance' => [
        'title'   => 'Staying compliant',
        'icon'    => 'shield',
        'summary' => 'Respect opt-outs and protect your numbers.',
        'steps'   => [
            'Opt-out keywords (Settings) unsubscribe anyone who replies them.',
            'Opted-out and blocked numbers are never messaged again.',
            'Add numbers to Do-not-contact manually or by bulk import.',
            'Use warm-up and daily caps on fresh numbers (Number health).',
        ],
        'example' => 'Someone replies "unsubscribe" — they are opted out and added to Do-not-contact instantly.',
        'tips'    => [
            'Only send to people who opted in — it is the #1 way to avoid blocks.',
            'Eagle has no ban-evasion tricks by design; safe sending is the goal.',
        ],
    ],

    'settings' => [
        'title'   => 'Settings, email & AI keys',
        'icon'    => 'cog',
        'summary' => 'Branding, SMTP email, and your AI key.',
        'steps'   => [
            'Branding: upload your logo and set the accent colour.',
            'Email (SMTP): enter your mail server, Save, then Send test email.',
            'AI: pick ChatGPT/Gemini/Claude, paste the key, Save, then Test connection.',
        ],
        'example' => 'You add your Gemini key and the ✨ Generate variants button in templates starts working.',
        'tips'    => [
            'Always Save before you press a Test button — tests use saved settings.',
        ],
    ],

];
