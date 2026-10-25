<?php

namespace App\Console\Commands;

use App\Support\IntelligenceReports;
use Illuminate\Console\Command;

class ScheduleIntelligenceReports extends Command
{
    protected $signature = 'ideyra:reports:dispatch';

    protected $description = 'Queue due reporting schedules after rechecking permissions and recipients';

    public function handle(IntelligenceReports $reports): int
    {
        $this->info('Queued reports: '.$reports->dispatchDue());
        $this->line('Generation requires the scheduler and an intelligence-reports queue worker. Email delivery is '.(IntelligenceReports::emailOperational() ? 'enabled by verified configuration.' : 'disabled or unverified; in-app notifications only.'));

        return self::SUCCESS;
    }
}
