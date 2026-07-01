<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ChatbotRule extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id', 'whatsapp_instance_id', 'name', 'match_type',
        'keywords', 'reply', 'use_ai', 'is_active', 'priority',
    ];

    protected $casts = [
        'use_ai'    => 'boolean',
        'is_active' => 'boolean',
    ];

    public function instance(): BelongsTo
    {
        return $this->belongsTo(WhatsappInstance::class, 'whatsapp_instance_id');
    }

    /**
     * Does the inbound text trigger this rule?
     */
    public function matches(string $text): bool
    {
        $text = mb_strtolower(trim($text));

        if ($this->match_type === 'any' || $this->match_type === 'ai') {
            return true;
        }

        $keywords = collect(explode(',', (string) $this->keywords))
            ->map(fn ($k) => mb_strtolower(trim($k)))
            ->filter();

        foreach ($keywords as $keyword) {
            $hit = match ($this->match_type) {
                'exact'       => $text === $keyword,
                'starts_with' => str_starts_with($text, $keyword),
                default       => str_contains($text, $keyword), // contains
            };

            if ($hit) {
                return true;
            }
        }

        return false;
    }
}
