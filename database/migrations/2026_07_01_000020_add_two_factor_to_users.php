<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('two_factor_enabled')->default(false)->after('role');
            $table->string('two_factor_type')->nullable()->after('two_factor_enabled'); // totp | email
            $table->text('two_factor_secret')->nullable()->after('two_factor_type');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['two_factor_enabled', 'two_factor_type', 'two_factor_secret']);
        });
    }
};
