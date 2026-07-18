<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn(['evolution_base_url', 'evolution_api_key', 'webjs_base_url', 'webjs_api_key']);
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->string('evolution_base_url')->nullable();
            $table->string('evolution_api_key')->nullable();
            $table->string('webjs_base_url')->nullable();
            $table->string('webjs_api_key')->nullable();
        });
    }
};
