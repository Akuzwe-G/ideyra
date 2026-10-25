<?php

namespace App\Jobs;

use App\Models\ReportRun;
use App\Support\IntelligenceReports;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class GenerateIntelligenceReport implements ShouldQueue
{
    use Queueable;

    public int $timeout = 180;

    public int $tries = 1;

    public function __construct(public int $runId) {}

    public function handle(IntelligenceReports $reports): void
    {
        $run = ReportRun::find($this->runId);
        if ($run) {
            $reports->generate($run);
        }
    }

    public function failed(?\Throwable $exception): void
    {
        ReportRun::whereKey($this->runId)->whereNotIn('status', ['ready'])->update(['status' => 'failed', 'failure_code' => 'worker_failed']);
    }
}
