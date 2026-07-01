<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('campaign_recipients', function (Blueprint $table) {
            $table->integer('variant_index')->nullable()->after('status'); // which A/B copy was sent
        });
    }

    public function down(): void
    {
        Schema::table('campaign_recipients', fn (Blueprint $table) => $table->dropColumn('variant_index'));
    }
};
