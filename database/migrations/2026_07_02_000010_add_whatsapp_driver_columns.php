<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Tenant-level default engine + whatsapp-web.js bridge credentials
        // (mirrors the existing evolution_base_url / evolution_api_key pattern).
        Schema::table('tenants', function (Blueprint $table) {
            if (! Schema::hasColumn('tenants', 'whatsapp_driver')) {
                $table->string('whatsapp_driver')->default('openwa')->after('evolution_api_key');
            }
            if (! Schema::hasColumn('tenants', 'webjs_base_url')) {
                $table->string('webjs_base_url')->nullable()->after('whatsapp_driver');
            }
            if (! Schema::hasColumn('tenants', 'webjs_api_key')) {
                $table->string('webjs_api_key')->nullable()->after('webjs_base_url');
            }
        });

        // Snapshot the engine each device was created on. A connected number keeps
        // using its own engine even if the tenant later flips the default driver.
        Schema::table('whatsapp_instances', function (Blueprint $table) {
            if (! Schema::hasColumn('whatsapp_instances', 'driver')) {
                $table->string('driver')->default('openwa')->after('instance_name');
            }
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn(['whatsapp_driver', 'webjs_base_url', 'webjs_api_key']);
        });

        Schema::table('whatsapp_instances', function (Blueprint $table) {
            $table->dropColumn('driver');
        });
    }
};
