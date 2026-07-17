<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('tenants')->update(['whatsapp_driver' => 'openwa']);
        DB::table('whatsapp_instances')->update(['driver' => 'openwa']);
    }

    public function down(): void
    {
        // Driver migration is intentionally one-way: Evolution sessions are not compatible with OpenWA.
    }
};
