<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('campaign_recipients', function (Blueprint $table) {
            // A poll is a two-message delivery. This checkpoint prevents retries
            // from sending the text/image prelude twice.
            $table->timestamp('prelude_sent_at')->nullable()->after('sent_at');
        });
    }

    public function down(): void
    {
        Schema::table('campaign_recipients', function (Blueprint $table) {
            $table->dropColumn('prelude_sent_at');
        });
    }
};
