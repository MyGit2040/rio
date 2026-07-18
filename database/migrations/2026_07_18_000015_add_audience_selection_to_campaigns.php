<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('campaigns', function (Blueprint $table) {
            $table->string('audience', 16)->default('all')->after('track_links');
            $table->json('group_ids')->nullable()->after('audience');
            $table->string('tag', 64)->nullable()->after('group_ids');
        });
    }

    public function down(): void
    {
        Schema::table('campaigns', function (Blueprint $table) {
            $table->dropColumn(['audience', 'group_ids', 'tag']);
        });
    }
};
