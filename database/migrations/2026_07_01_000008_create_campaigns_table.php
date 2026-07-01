<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('campaigns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('whatsapp_instance_id')->nullable()->constrained('whatsapp_instances')->nullOnDelete();
            $table->foreignId('template_id')->nullable()->constrained('templates')->nullOnDelete();
            $table->string('name');
            $table->string('type')->default('text');     // text|media|poll (snapshot of how it sends)
            $table->text('body')->nullable();             // resolved message (if not using a template)
            $table->string('media_url')->nullable();
            $table->string('media_type')->nullable();
            $table->json('poll')->nullable();
            $table->string('status')->default('draft');   // draft|scheduled|sending|paused|completed|failed
            $table->unsignedInteger('min_delay')->default(5);   // seconds between sends (anti-ban)
            $table->unsignedInteger('max_delay')->default(15);
            $table->timestamp('scheduled_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->unsignedInteger('total')->default(0);
            $table->unsignedInteger('sent')->default(0);
            $table->unsignedInteger('failed')->default(0);
            $table->timestamps();

            $table->index(['tenant_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('campaigns');
    }
};
