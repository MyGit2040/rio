<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('campaign_recipients', function (Blueprint $table) {
            $table->foreignId('preferred_whatsapp_instance_id')->nullable()
                ->after('whatsapp_instance_id')->constrained('whatsapp_instances')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('campaign_recipients', function (Blueprint $table) {
            $table->dropConstrainedForeignId('preferred_whatsapp_instance_id');
        });
    }
};
