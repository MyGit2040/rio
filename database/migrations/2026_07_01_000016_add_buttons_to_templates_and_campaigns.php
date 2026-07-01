<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Interactive button payload: { title, footer, items:[{type,text,value}] }
        Schema::table('templates', function (Blueprint $table) {
            $table->json('buttons')->nullable()->after('poll');
        });
        Schema::table('campaigns', function (Blueprint $table) {
            $table->json('buttons')->nullable()->after('poll');
        });
    }

    public function down(): void
    {
        Schema::table('templates', fn (Blueprint $table) => $table->dropColumn('buttons'));
        Schema::table('campaigns', fn (Blueprint $table) => $table->dropColumn('buttons'));
    }
};
