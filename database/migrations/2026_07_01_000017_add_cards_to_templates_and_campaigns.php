<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Carousel cards: [{ image, title, body, buttons:[{type,text,value}] }] (up to 10).
        Schema::table('templates', function (Blueprint $table) {
            $table->json('cards')->nullable()->after('buttons');
        });
        Schema::table('campaigns', function (Blueprint $table) {
            $table->json('cards')->nullable()->after('buttons');
        });
    }

    public function down(): void
    {
        Schema::table('templates', fn (Blueprint $table) => $table->dropColumn('cards'));
        Schema::table('campaigns', fn (Blueprint $table) => $table->dropColumn('cards'));
    }
};
