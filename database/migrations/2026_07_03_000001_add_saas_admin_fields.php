<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('is_super_admin')->default(false)->after('role');
        });

        Schema::table('tenants', function (Blueprint $table) {
            $table->timestamp('expires_at')->nullable()->after('status');   // subscription end (null = no expiry)
            $table->unsignedInteger('max_devices')->default(0)->after('expires_at'); // 0 = use plan default
            $table->json('enabled_modules')->nullable()->after('max_devices'); // null = all modules
        });
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('is_super_admin'));
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn(['expires_at', 'max_devices', 'enabled_modules']);
        });
    }
};
