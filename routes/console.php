<?php

use App\Models\AiConversation;
use App\Models\AiUsage;
use App\Support\AiProviderContract;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Schedule::command('ideyra:projects:remind')->dailyAt('08:00')->withoutOverlapping();
Schedule::command('ideyra:opportunities:remind')->hourly()->withoutOverlapping();
Schedule::command('ideyra:ai:purge')->daily()->withoutOverlapping();

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
