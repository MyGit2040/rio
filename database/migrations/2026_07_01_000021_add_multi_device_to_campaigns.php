<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Devices selected for rotation. whatsapp_instance_id stays as the primary/first.
        Schema::table('campaigns', function (Blueprint $table) {
            $table->json('device_ids')->nullable()->after('whatsapp_instance_id');
        });

        // Which device actually sent each recipient (sticky per contact).
        Schema::table('campaign_recipients', function (Blueprint $table) {
            $table->foreignId('whatsapp_instance_id')->nullable()->after('campaign_id')
                ->constrained('whatsapp_instances')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('campaigns', fn (Blueprint $table) => $table->dropColumn('device_ids'));
        Schema::table('campaign_recipients', function (Blueprint $table) {
            $table->dropConstrainedForeignId('whatsapp_instance_id');
        });
    }
};
