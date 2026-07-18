<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contacts', function (Blueprint $table) {
            $table->boolean('marketing_opted_in')->default(false)->after('opted_out');
            $table->timestamp('marketing_opted_in_at')->nullable()->after('marketing_opted_in');
            $table->string('marketing_consent_source', 100)->nullable()->after('marketing_opted_in_at');
            $table->index(['tenant_id', 'opted_out', 'marketing_opted_in'], 'contacts_marketing_eligibility_index');
        });
    }

    public function down(): void
    {
        Schema::table('contacts', function (Blueprint $table) {
            $table->dropIndex('contacts_marketing_eligibility_index');
            $table->dropColumn(['marketing_opted_in', 'marketing_opted_in_at', 'marketing_consent_source']);
        });
    }
};
