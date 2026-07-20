<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('whatsapp_instances', function (Blueprint $table) {
            $table->text('google_contacts_token')->nullable()->after('google_contacts_email');
            $table->timestamp('google_contacts_connected_at')->nullable()->after('google_contacts_token');
        });

        Schema::create('google_contact_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('whatsapp_instance_id')->constrained()->cascadeOnDelete();
            $table->foreignId('contact_id')->constrained()->cascadeOnDelete();
            $table->string('resource_name');
            $table->timestamps();
            $table->unique(['whatsapp_instance_id', 'contact_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('google_contact_links');
        Schema::table('whatsapp_instances', function (Blueprint $table) {
            $table->dropColumn(['google_contacts_token', 'google_contacts_connected_at']);
        });
    }
};
