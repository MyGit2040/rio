<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contacts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('name')->nullable();
            $table->string('phone');                 // E.164 digits, no +
            $table->string('email')->nullable();
            $table->string('country')->nullable();
            $table->json('attributes')->nullable();  // arbitrary merge fields for personalization
            $table->boolean('opted_out')->default(false);
            $table->timestamps();

            $table->unique(['tenant_id', 'phone']);
            $table->index('tenant_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contacts');
    }
};
