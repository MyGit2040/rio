<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('contact_id')->nullable()->constrained('contacts')->nullOnDelete();
            $table->string('number')->unique();
            $table->string('phone')->nullable();
            $table->string('status')->default('pending'); // pending|paid|cancelled
            $table->string('currency', 8)->default('USD');
            $table->decimal('total', 15, 2)->default(0);
            $table->json('items')->nullable();            // [{name, quantity, price}]
            $table->timestamps();

            $table->index(['tenant_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoices');
    }
};
