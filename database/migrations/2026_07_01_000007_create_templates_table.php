<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('templates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('name');
            $table->string('type')->default('text');   // text|media|poll
            $table->text('body')->nullable();           // message text, supports {{name}} merge tags
            $table->string('media_url')->nullable();
            $table->string('media_type')->nullable();   // image|video|document|audio
            // Poll payload: { "question": "...", "options": ["a","b"], "multiple": false }
            $table->json('poll')->nullable();
            $table->timestamps();

            $table->index('tenant_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('templates');
    }
};
