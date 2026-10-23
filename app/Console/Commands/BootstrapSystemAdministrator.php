<?php

namespace App\Console\Commands;

use App\Models\Role;
use App\Models\User;
use App\Support\Audit;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;
use RuntimeException;
use Throwable;

class BootstrapSystemAdministrator extends Command
{
    protected $signature = 'ideyra:admin:bootstrap';

    protected $description = 'Trusted local-only first administrator bootstrap; does not verify email ownership';

    public function handle(): int
    {
        if (PHP_SAPI !== 'cli' || ! app()->runningInConsole()) {
            return self::FAILURE;
        }
        $credentials = config('admin-bootstrap', []);
        foreach (['email', 'name'] as $field) {
            if (is_string($credentials[$field] ?? null)) {
                $credentials[$field] = trim($credentials[$field]);
            }
        }
        if (is_string($credentials['email'] ?? null)) {
            $credentials['email'] = mb_strtolower($credentials['email']);
        }
        $validator = Validator::make($credentials, [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255'],
            'password' => ['required', 'string', 'max:72', Password::min(16)->mixedCase()->numbers()->symbols()],
        ]);
        if ($validator->fails() || strlen((string) ($credentials['password'] ?? '')) > 72) {
            $this->error('Invalid bootstrap configuration. Supply name, valid email and a password of 16 or more characters (maximum 72 bytes), including upper/lowercase letters, numbers and symbols. No account created.');

            return self::FAILURE;
        }
        try {
            DB::transaction(function () use ($credentials): void {
                app(RolePermissionSeeder::class)->run();
                $role = Role::where('name', 'System Administrator')->lockForUpdate()->sole();
                if ($role->scope !== 'platform'
                    || User::whereRaw('LOWER(email) = ?', [$credentials['email']])->exists()
                    || DB::table('user_roles')->where('role_id', $role->id)->exists()) {
                    throw new RuntimeException('Bootstrap conflict');
                }
                $user = new User;
                $user->name = $credentials['name'];
                $user->email = $credentials['email'];
                $user->password = Hash::make($credentials['password']);
                $user->status = 'active';
                $user->email_verified_at = null;
                $user->save();
                $user->roles()->attach($role);
                Audit::record('system.administrator_bootstrapped', $user, null, [
                    'source' => 'trusted_local_console', 'email_ownership_verified' => false,
                ], $user->id);
            });
        } catch (Throwable) {
            $this->error('Bootstrap stopped safely: existing identity/administrator conflict or database failure. No credentials were changed.');

            return self::FAILURE;
        } finally {
            unset($credentials);
            config(['admin-bootstrap.password' => null]);
        }
        $this->info('System Administrator created, active and authorised for /system. Email ownership remains unverified under the trusted local operator policy.');
        $this->warn('Remove IDEYRA_ADMIN_NAME, IDEYRA_ADMIN_EMAIL and IDEYRA_ADMIN_PASSWORD from .env now. Then run php artisan config:clear. If deployment caches configuration, run php artisan config:cache only after removal. Treat old cached copies as secrets.');

        return self::SUCCESS;
    }
}
