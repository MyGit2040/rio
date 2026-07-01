<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('templates', function (Blueprint $table) {
            if (! Schema::hasColumn('templates', 'footer')) {
                $table->text('footer')->nullable()->after('body');
            }
        });

        Schema::table('campaigns', function (Blueprint $table) {
            if (! Schema::hasColumn('campaigns', 'footer')) {
                $table->text('footer')->nullable()->after('body');
            }
        });
    }

    public function down(): void
    {
        Schema::table('templates', fn (Blueprint $t) => $t->dropColumn('footer'));
        Schema::table('campaigns', fn (Blueprint $t) => $t->dropColumn('footer'));
    }
};
