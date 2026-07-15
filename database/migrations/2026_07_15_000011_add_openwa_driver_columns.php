<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            foreach (['openwa_base_url', 'openwa_api_key', 'openwa_session_id'] as $column) {
                if (! Schema::hasColumn('tenants', $column)) {
                    $table->string($column)->nullable();
                }
            }
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn(['openwa_base_url', 'openwa_api_key', 'openwa_session_id']);
        });
    }
};
