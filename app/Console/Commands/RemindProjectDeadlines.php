<?php

namespace App\Console\Commands;

use App\Models\ProjectTask;
use App\Support\InnovationAccess;
use App\Support\ProjectSignals;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class RemindProjectDeadlines extends Command
{
    protected $signature = 'ideyra:projects:remind';

    protected $description = 'Send deduplicated private reminders for tasks due tomorrow or overdue';

    public function handle(): int
    {
        $sent = 0;
        ProjectTask::with('project', 'assignees')->where('status', '!=', 'completed')->whereDate('due_at', '<=', today()->addDay())
            ->whereHas('project', fn ($q) => $q->whereNotIn('status', ['completed', 'cancelled', 'archived']))->chunkById(100, function ($tasks) use (&$sent): void {
                foreach ($tasks as $task) {
                    foreach ($task->assignees as $user) {
                        if (! InnovationAccess::projects($user)->whereKey($task->project_id)->exists() || $user->status !== 'active') {
                            continue;
                        }
                        DB::transaction(function () use ($task, $user, &$sent): void {
                            $created = DB::table('task_reminders')->insertOrIgnore(['task_id' => $task->id, 'user_id' => $user->id, 'due_at' => $task->due_at->toDateString(), 'created_at' => now(), 'updated_at' => now()]);
                            if ($created) {
                                ProjectSignals::send([$user->id], 'project', $task->project, 'Task deadline reminder', 'An assigned task is due soon or overdue. Open your project to review it.');
                                $sent++;
                            }
                        });
                    }
                }
            });
        $this->info($sent.' task reminders recorded.');

        return self::SUCCESS;
    }
}
