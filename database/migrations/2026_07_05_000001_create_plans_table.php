<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plans', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();               // slug used by tenants.plan (free, pro, business)
            $table->string('name');
            $table->string('description')->nullable();
            $table->decimal('price', 10, 2)->default(0);    // monthly price
            $table->decimal('annual_price', 10, 2)->nullable();
            $table->string('billing_period')->default('monthly'); // monthly | yearly | one_time
            $table->json('limits')->nullable();             // {devices, contacts, monthly_messages}; 0 = unlimited
            $table->text('features')->nullable();           // one per line; prefix "-" for an excluded feature
            $table->boolean('is_popular')->default(false);
            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true);
            $table->integer('sort_order')->default(0);
            $table->timestamps();
        });

        // Seed the existing config tiers so live tenants keep their exact limits.
        $defaultKey = config('plans.default', 'free');
        $order = 0;

        foreach ((array) config('plans.tiers', []) as $key => $tier) {
            DB::table('plans')->insert([
                'key'            => $key,
                'name'           => $tier['name'] ?? ucfirst($key),
                'description'    => null,
                'price'          => $tier['price'] ?? 0,
                'annual_price'   => null,
                'billing_period' => 'monthly',
                'limits'         => json_encode($tier['limits'] ?? []),
                'features'       => implode("\n", $tier['features'] ?? []),
                'is_popular'     => $key === 'pro',
                'is_default'     => $key === $defaultKey,
                'is_active'      => true,
                'sort_order'     => $order++,
                'created_at'     => now(),
                'updated_at'     => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('plans');
    }
};
