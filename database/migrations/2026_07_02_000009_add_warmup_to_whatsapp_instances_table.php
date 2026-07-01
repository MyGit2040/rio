<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('whatsapp_instances', function (Blueprint $table) {
            // Gradual ramp for a fresh number: start low, add N/day up to daily_limit.
            $table->boolean('warmup_enabled')->default(false)->after('daily_limit');
            $table->unsignedInteger('warmup_start')->default(20)->after('warmup_enabled');
            $table->unsignedInteger('warmup_per_day')->default(20)->after('warmup_start');
            $table->date('warmup_started_at')->nullable()->after('warmup_per_day');
        });
    }

    public function down(): void
    {
        Schema::table('whatsapp_instances', function (Blueprint $table) {
            $table->dropColumn(['warmup_enabled', 'warmup_start', 'warmup_per_day', 'warmup_started_at']);
        });
    }
};
