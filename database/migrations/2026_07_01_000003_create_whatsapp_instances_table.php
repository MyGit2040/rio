<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('whatsapp_instances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('name');                       // friendly label e.g. "Sales line"
            $table->string('instance_name')->unique();    // unique key on the Evolution server
            $table->string('token')->nullable();          // instance token returned by Evolution
            $table->string('status')->default('created'); // created|connecting|open|close
            $table->string('phone_number')->nullable();
            $table->string('profile_name')->nullable();
            $table->text('qr_code')->nullable();          // last QR (base64 data URL)
            $table->timestamp('connected_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_instances');
    }
};
