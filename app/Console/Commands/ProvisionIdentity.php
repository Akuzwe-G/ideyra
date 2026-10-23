<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Support\Audit;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

class ProvisionIdentity extends Command
{
    protected $signature = 'ideyra:provision-identity {email} {--name=} {--verified-email=}';

    protected $description = 'Provision an explicitly verified initial identity through a private interactive console; grants no role';

    public function handle(): int
    {
        $email = mb_strtolower(trim($this->argument('email')));
        if (! $this->input->isInteractive() || ! hash_equals($email, (string) $this->option('verified-email'))) {
            $this->error('Interactive authorised console and matching verified email are required.');

            return self::FAILURE;
        }
        if (User::where('email', $email)->exists()) {
            $this->info('Existing identity preserved; no password or privileges changed.');

            return self::SUCCESS;
        }
        $password = $this->secret('Choose a private initial password (never printed or emailed)');
        $confirmation = $this->secret('Confirm password');
        $data = ['name' => $this->option('name'), 'email' => $email, 'password' => $password, 'password_confirmation' => $confirmation];
        $validator = Validator::make($data, ['name' => ['required', 'string', 'max:100'], 'email' => ['required', 'email', 'max:255'], 'password' => ['required', 'confirmed', Password::min(12)->mixedCase()->numbers()]]);
        if ($validator->fails()) {
            $this->error('Identity validation failed; use a full name, valid email and matching strong passwords.');

            return self::FAILURE;
        }
        DB::transaction(function () use ($data): void {
            $user = User::create(collect($data)->only('name', 'email', 'password')->all());
            $user->forceFill(['email_verified_at' => now()])->save();
            Audit::record('identity.console_provisioned', $user, null, ['source' => 'verified_operator'], $user->id);
        });
        $this->info('Identity provisioned with no role. System Administrator assignment requires the separate verified command.');

        return self::SUCCESS;
    }
}
