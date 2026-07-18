<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Earlier releases did not retain the audience choice. Mark their
        // defaulted values as unknown so editing can never broaden a former
        // group campaign to every contact without an explicit user choice.
        DB::table('campaigns')
            ->where('audience', 'all')
            ->whereNull('group_ids')
            ->whereNull('tag')
            ->update(['audience' => 'legacy']);
    }

    public function down(): void
    {
        // No safe way exists to infer whether a legacy audience was all/groups/tag.
    }
};
