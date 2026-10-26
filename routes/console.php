<?php

use App\Models\AiConversation;
use App\Models\AiUsage;
use App\Models\CollaborationRequest;
use App\Support\AiProviderContract;
use App\Support\Audit;
use App\Support\IntelligenceReports;
use App\Support\NetworkSignals;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schedule;

Schedule::command('ideyra:projects:remind')->dailyAt('08:00')->withoutOverlapping();
Schedule::command('ideyra:opportunities:remind')->hourly()->withoutOverlapping();
Schedule::command('ideyra:ai:purge')->daily()->withoutOverlapping();
Schedule::command('ideyra:reports:dispatch')->hourly()->withoutOverlapping();
Schedule::command('ideyra:reports:purge')->daily()->withoutOverlapping();
Schedule::command('ideyra:network:expire')->hourly()->withoutOverlapping();

Artisan::command('ideyra:network:expire', function (): void {
    $count = 0;
    CollaborationRequest::whereIn('status', ['draft', 'submitted', 'under_review'])->where('expires_at', '<=', now())->chunkById(100, function ($requests) use (&$count): void {
        foreach ($requests as $request) {
            DB::transaction(function () use ($request, &$count): void {
                $record = CollaborationRequest::whereKey($request->id)->lockForUpdate()->firstOrFail();
                if (! in_array($record->status, ['draft', 'submitted', 'under_review'], true) || $record->expires_at->isFuture()) {
                    return;
                }
                $record->update(['status' => 'expired', 'active_key' => null]);
                Audit::record('network.collaboration.expired', $record, $record->institution_id, ['status' => 'expired']);
                NetworkSignals::send([$record->user_id, $record->representative_id], 'network_request', $record, 'expired', 'A collaboration request expired');
                $count++;
            });
        }
    });
    $this->info('Expired collaboration requests: '.$count);
})->purpose('Expire overdue collaboration requests without granting access or creating workspaces');

Artisan::command('ideyra:reports:purge', function (): void {
    $this->info('Expired private report snapshots removed: '.app(IntelligenceReports::class)->purge());
})->purpose('Remove expired report snapshots and private export files');

Artisan::command('ideyra:ai:purge', function (): void {
    $count = AiConversation::where('expires_at', '<=', now())->delete();
    AiUsage::where('created_at', '<', now()->subDays(365))->delete();
    $this->info('Expired private AI conversations removed: '.$count);
})->purpose('Delete expired private AI conversations and insights');

Artisan::command('ideyra:ai:status', function (): void {
    $this->info(app(AiProviderContract::class)->available()
        ? 'AI provider is configured. Live connectivity is unverified.'
        : 'AI provider unavailable: enable and configure an approved provider locally.');
})->purpose('Report AI readiness without displaying credentials');

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');
