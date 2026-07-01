<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('campaigns', function (Blueprint $table) {
            $table->unsignedInteger('max_retries')->default(3)->after('max_delay');
        });

        Schema::table('campaign_recipients', function (Blueprint $table) {
            $table->unsignedInteger('attempts')->default(0)->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('campaigns', function (Blueprint $table) {
            $table->dropColumn('max_retries');
        });
        Schema::table('campaign_recipients', function (Blueprint $table) {
            $table->dropColumn('attempts');
        });
    }
};
