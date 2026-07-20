<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('device_health_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('whatsapp_instance_id')->constrained()->cascadeOnDelete();
            $table->foreignId('campaign_id')->nullable()->constrained()->nullOnDelete();
            $table->string('event', 40);
            $table->string('severity', 20)->default('info');
            $table->text('message')->nullable();
            $table->timestamps();

            $table->index(['whatsapp_instance_id', 'event', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('device_health_events');
    }
};
