<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contacts', function (Blueprint $table) {
            // WhatsApp existence check: unverified | valid | invalid
            $table->string('wa_status')->default('unverified')->after('country');
            $table->timestamp('verified_at')->nullable()->after('wa_status');
        });
    }

    public function down(): void
    {
        Schema::table('contacts', function (Blueprint $table) {
            $table->dropColumn(['wa_status', 'verified_at']);
        });
    }
};
