<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds the Baileys gateway as a selectable sending engine.
 *
 * Per-workspace connection settings mirror the existing openwa_* columns, and
 * the processed-webhook table makes Laravel an idempotent consumer: the gateway
 * guarantees at-least-once delivery, so the same event_id can legitimately
 * arrive more than once and must only take effect once.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            foreach (['baileys_base_url', 'baileys_api_key', 'baileys_signing_secret'] as $column) {
                if (! Schema::hasColumn('tenants', $column)) {
                    $table->string($column)->nullable();
                }
            }
        });

        if (! Schema::hasTable('baileys_webhook_events')) {
            Schema::create('baileys_webhook_events', function (Blueprint $table) {
                $table->id();
                // The gateway's event_id. Unique so a redelivery is rejected by
                // the database rather than by a check that could race.
                $table->string('event_id')->unique();
                $table->string('event_type')->index();
                $table->timestamp('processed_at');
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn(['baileys_base_url', 'baileys_api_key', 'baileys_signing_secret']);
        });

        Schema::dropIfExists('baileys_webhook_events');
    }
};
