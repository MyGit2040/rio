<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chatbot_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('whatsapp_instance_id')->nullable()->constrained('whatsapp_instances')->cascadeOnDelete();
            $table->string('name');
            $table->string('match_type')->default('contains'); // contains|exact|starts_with|any|ai
            $table->string('keywords')->nullable();             // comma separated triggers
            $table->text('reply')->nullable();                  // static reply (when not AI)
            $table->boolean('use_ai')->default(false);
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('priority')->default(100);
            $table->timestamps();

            $table->index(['tenant_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chatbot_rules');
    }
};
