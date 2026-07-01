<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('suppressions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('phone');                 // digits only
            $table->string('reason')->nullable();
            $table->string('source')->default('manual'); // manual | opt_out | import | bounce
            $table->timestamps();

            $table->unique(['tenant_id', 'phone']);
            $table->index('tenant_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('suppressions');
    }
};
