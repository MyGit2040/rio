<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('campaigns', function (Blueprint $table) {
            // Per-device message cap for this campaign: { "<device_id>": <max> }.
            // A device absent or 0 = unlimited. Lets the operator fix how many
            // messages each selected WhatsApp number may send.
            $table->json('device_limits')->nullable()->after('rotate_every');
        });
    }

    public function down(): void
    {
        Schema::table('campaigns', fn (Blueprint $table) => $table->dropColumn('device_limits'));
    }
};
