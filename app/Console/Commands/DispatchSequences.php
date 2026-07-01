<?php

namespace App\Console\Commands;

use App\Services\SequenceService;
use Illuminate\Console\Command;

class DispatchSequences extends Command
{
    protected $signature = 'sequences:dispatch';

    protected $description = 'Send the next due step for every active drip-sequence enrollment';

    public function handle(SequenceService $sequences): int
    {
        $sent = $sequences->dispatchDue();

        $this->info("{$sent} sequence step(s) sent.");

        return self::SUCCESS;
    }
}
