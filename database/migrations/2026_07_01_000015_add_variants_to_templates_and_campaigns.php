<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Author-written alternative message bodies that rotate per send (A/B copy rotation).
        Schema::table('templates', function (Blueprint $table) {
            $table->json('variants')->nullable()->after('body');
        });

        Schema::table('campaigns', function (Blueprint $table) {
            $table->json('variants')->nullable()->after('body');
        });
    }

    public function down(): void
    {
        Schema::table('templates', fn (Blueprint $table) => $table->dropColumn('variants'));
        Schema::table('campaigns', fn (Blueprint $table) => $table->dropColumn('variants'));
    }
};
