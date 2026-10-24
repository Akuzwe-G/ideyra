<?php

namespace App\Console\Commands;

use App\Models\Opportunity;
use App\Models\User;
use App\Support\OpportunityAccess;
use App\Support\OpportunitySignals;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class RemindOpportunityDeadlines extends Command
{
    protected $signature = 'ideyra:opportunities:remind';

    protected $description = 'Send deduplicated notices for bookmarked opportunities closing within 72 hours';

    public function handle(): int
    {
        $sent = 0;
        Opportunity::where('status', 'published')->whereBetween('deadline_at', [now(), now()->addHours(72)])->where(fn ($q) => $q->whereNull('opens_at')->orWhere('opens_at', '<=', now()))->chunkById(100, function ($opportunities) use (&$sent): void {
            foreach ($opportunities as $opportunity) {
                $userIds = DB::table('opportunity_bookmarks')->where('opportunity_id', $opportunity->id)->pluck('user_id');
                foreach (User::whereIn('id', $userIds)->where('status', 'active')->get() as $user) {
                    if (! OpportunityAccess::visible($user, false)->whereKey($opportunity->id)->exists()) {
                        continue;
                    }
                    DB::transaction(function () use ($user, $opportunity, &$sent): void {
                        if (DB::table('opportunity_notices')->insertOrIgnore(['opportunity_id' => $opportunity->id, 'user_id' => $user->id, 'event_key' => 'deadline:'.$opportunity->deadline_at->utc()->format('YmdHis'), 'created_at' => now(), 'updated_at' => now()])) {
                            OpportunitySignals::send([$user->id], 'opportunity', $opportunity, 'Saved opportunity deadline approaching', 'A bookmarked opportunity closes within 72 hours. Check its current requirements.');
                            $sent++;
                        }
                    });
                }
            }
        });
        $this->info($sent.' opportunity deadline notices recorded.');

        return self::SUCCESS;
    }
}
