<?php

namespace App\Console\Commands;

use App\Models\Membership;
use App\Support\IdentityOnboarding;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class PrivateActivationLink extends Command
{
    protected $signature = 'ideyra:activation-link {membership} {--verified-email=}';

    protected $description = 'Reissue a pending invitation into a private file after out-of-band identity verification';

    public function handle(IdentityOnboarding $onboarding): int
    {
        $member = Membership::with('user')->find($this->argument('membership'));
        if (! $member || $member->status !== 'pending' || ! hash_equals($member->user->email, (string) $this->option('verified-email'))) {
            $this->error('A pending membership and its verified contact email are required.');

            return self::FAILURE;
        }
        $url = $onboarding->invite($member, false);
        $path = 'activation-links/'.Str::uuid().'.txt';
        Storage::disk('local')->put($path, $url);
        @chmod(Storage::disk('local')->path($path), 0600);
        $this->info('Private handoff file: '.Storage::disk('local')->path($path));

        return self::SUCCESS;
    }
}
