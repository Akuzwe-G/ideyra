<?php

namespace App\Console\Commands;

use App\Models\Role;
use App\Models\User;
use App\Support\Audit;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class AssignSystemAdministrator extends Command
{
    protected $signature = 'ideyra:system-administrator {email} {--confirm-email=}';

    protected $description = 'Assign System Administrator to an explicitly verified existing active user';

    public function handle(): int
    {
        $email = mb_strtolower(trim($this->argument('email')));
        if (! hash_equals($email, (string) $this->option('confirm-email'))) {
            $this->error('Verify the authorised identity out of band and provide the matching --confirm-email value.');

            return self::FAILURE;
        }
        $user = User::where('email', $email)->where('status', 'active')->first();
        if (! $user) {
            $this->error('An existing active user is required. No account was created.');

            return self::FAILURE;
        }
        $this->call('db:seed', ['--class' => RolePermissionSeeder::class, '--no-interaction' => true]);
        DB::transaction(function () use ($user): void {
            $role = Role::where('name', 'System Administrator')->firstOrFail();
            $changed = $user->roles()->syncWithoutDetaching([$role->id]);
            if ($changed['attached']) {
                Audit::record('system.administrator_assigned', $user, null, ['source' => 'authorised_console'], $user->id);
            }
        });
        $this->info('System Administrator assignment verified. No password was created or changed.');

        return self::SUCCESS;
    }
}
