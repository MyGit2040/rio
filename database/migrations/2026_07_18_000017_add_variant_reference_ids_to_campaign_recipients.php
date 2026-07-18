<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('campaign_recipients', function (Blueprint $table) {
            $table->string('variant_ref_id', 6)->nullable()->after('variant_index');
            $table->unique(['campaign_id', 'variant_ref_id'], 'campaign_recipients_campaign_ref_unique');
        });
    }

    public function down(): void
    {
        Schema::table('campaign_recipients', function (Blueprint $table) {
            $table->dropUnique('campaign_recipients_campaign_ref_unique');
            $table->dropColumn('variant_ref_id');
        });
    }
};
