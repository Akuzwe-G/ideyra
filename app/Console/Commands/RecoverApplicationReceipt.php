<?php

namespace App\Console\Commands;

use App\Models\JoinApplication;
use App\Support\ApplicationWorkflow;
use App\Support\Audit;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class RecoverApplicationReceipt extends Command
{
    protected $signature = 'ideyra:application-receipt {reference} {--verified-email=}';

    protected $description = 'Recover a signed receipt to a private file after out-of-band applicant verification';

    public function handle(ApplicationWorkflow $workflow): int
    {
        $application = JoinApplication::where('reference', $this->argument('reference'))->first();
        if (! $application || ! hash_equals($application->payload['email'], (string) $this->option('verified-email'))) {
            $this->error('A matching application reference and verified applicant email are required.');

            return self::FAILURE;
        }
        $path = 'application-receipts/'.Str::uuid().'.txt';
        Storage::disk('local')->put($path, $workflow->receiptUrl($application));
        @chmod(Storage::disk('local')->path($path), 0600);
        Audit::record('application.receipt_reissued', $application, null, ['source' => 'verified_operator']);
        $this->info('Private receipt file: '.Storage::disk('local')->path($path));

        return self::SUCCESS;
    }
}
