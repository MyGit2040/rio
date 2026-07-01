<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('whatsapp_instance_id')->nullable()->constrained('whatsapp_instances')->nullOnDelete();
            $table->foreignId('contact_id')->nullable()->constrained('contacts')->nullOnDelete();
            $table->foreignId('campaign_id')->nullable()->constrained('campaigns')->nullOnDelete();
            $table->string('direction');                  // in|out
            $table->string('remote_jid')->nullable();     // 9715xxxx@s.whatsapp.net
            $table->string('phone')->nullable();
            $table->string('type')->default('text');
            $table->text('body')->nullable();
            $table->string('status')->nullable();
            $table->string('message_id')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'remote_jid']);
            $table->index('direction');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('messages');
    }
};
