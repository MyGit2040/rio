<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tracked_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('campaign_id')->nullable()->constrained('campaigns')->cascadeOnDelete();
            $table->string('token', 12)->unique();
            $table->text('url');
            $table->unsignedInteger('clicks')->default(0);
            $table->timestamps();

            $table->index('tenant_id');
        });

        Schema::create('link_clicks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tracked_link_id')->constrained('tracked_links')->cascadeOnDelete();
            $table->string('phone')->nullable();
            $table->string('ip', 45)->nullable();
            $table->string('user_agent', 512)->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index('tracked_link_id');
        });

        Schema::table('campaigns', function (Blueprint $table) {
            $table->boolean('track_links')->default(false)->after('cards');
        });
    }

    public function down(): void
    {
        Schema::table('campaigns', fn (Blueprint $table) => $table->dropColumn('track_links'));
        Schema::dropIfExists('link_clicks');
        Schema::dropIfExists('tracked_links');
    }
};
