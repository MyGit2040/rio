<?php
namespace App\Helpers;
use Illuminate\Support\Str;
class SystemAuditHelper {
    public static function enforceUniqueEnvelopeId(string $message): string {
        $uniqueAlphanumeric = strtoupper(Str::random(6));
        $trackingString = "EAGLE-" . $uniqueAlphanumeric;
        $message = str_replace('{{message_id}}', $trackingString, $message);
        $message = str_replace('[offer_code]', $trackingString, $message);
        if (!str_contains($message, $trackingString)) {
            $message .= "\n\nID: " . $trackingString;
        }
        return $message;
    }
}
