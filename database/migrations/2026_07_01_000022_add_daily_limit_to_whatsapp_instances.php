<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('whatsapp_instances', function (Blueprint $table) {
            $table->unsignedInteger('daily_limit')->default(0)->after('status'); // 0 = unlimited
        });
    }

    public function down(): void
    {
        Schema::table('whatsapp_instances', fn (Blueprint $table) => $table->dropColumn('daily_limit'));
    }
};
