<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class AdminBootstrapTest extends TestCase
{
    use RefreshDatabase;

    private function credentials(): array
    {
        $credentials = ['name' => 'Bootstrap operator', 'email' => 'bootstrap@example.test', 'password' => Str::password(32)];
        config(['admin-bootstrap' => $credentials]);

        return $credentials;
    }

    public function test_bootstrap_hashes_credentials_assigns_platform_role_and_allows_login_and_administration(): void
    {
        $credentials = $this->credentials();
        $this->assertSame(0, Artisan::call('ideyra:admin:bootstrap'));
        $this->assertStringNotContainsString($credentials['password'], Artisan::output());
        $user = User::sole();
        $this->assertTrue(Hash::check($credentials['password'], $user->password));
        $this->assertNull($user->email_verified_at);
        $this->assertSame('active', $user->status);
        $role = $user->roles()->sole();
        $this->assertSame('System Administrator', $role->name);
        $this->assertSame('platform', $role->scope);
        $this->assertContains('system.institutions', $role->permissions);
        $this->assertContains('system.applications', $role->permissions);
        $this->post(route('login.store'), ['email' => $credentials['email'], 'password' => $credentials['password']])->assertRedirect(route('system.dashboard'));
        $this->assertAuthenticatedAs($user);
        foreach (['system.dashboard', 'institutions.index', 'institutions.create', 'system.applications'] as $route) {
            $this->get(route($route))->assertOk();
        }
        $this->assertDatabaseHas('audit_entries', ['action' => 'system.administrator_bootstrapped']);
        config(['admin-bootstrap' => $credentials]);
        $this->artisan('ideyra:admin:bootstrap')->assertFailed();
        $this->assertDatabaseCount('users', 1);
        $this->assertSame($user->password, $user->fresh()->password);
    }

    public function test_existing_identity_is_never_elevated_or_modified(): void
    {
        $credentials = $this->credentials();
        $user = User::factory()->create(['email' => strtoupper($credentials['email']), 'status' => 'pending']);
        $before = $user->fresh()->getAttributes();
        $this->artisan('ideyra:admin:bootstrap')->assertFailed();
        $this->assertSame($before, $user->fresh()->getAttributes());
        $this->assertSame(0, $user->roles()->count());
    }

    public function test_first_admin_prevents_bootstrapping_another_identity(): void
    {
        $this->credentials();
        $this->artisan('ideyra:admin:bootstrap')->assertSuccessful();
        $this->credentials();
        config(['admin-bootstrap.email' => 'other@example.test']);
        $this->artisan('ideyra:admin:bootstrap')->assertFailed();
        $this->assertDatabaseCount('users', 1);
    }

    public function test_invalid_or_missing_credentials_fail_without_exposing_input(): void
    {
        foreach ([[], ['name' => 'Operator', 'email' => 'invalid', 'password' => Str::random(8)]] as $credentials) {
            config(['admin-bootstrap' => $credentials]);
            $this->assertSame(1, Artisan::call('ideyra:admin:bootstrap'));
            if (isset($credentials['password'])) {
                $this->assertStringNotContainsString($credentials['password'], Artisan::output());
            }
        }
        $this->assertDatabaseCount('users', 0);
    }

    public function test_administration_stays_private_and_suspended_admin_is_denied(): void
    {
        $this->get(route('system.dashboard'))->assertRedirect(route('login'));
        $this->actingAs(User::factory()->create())->get(route('system.dashboard'))->assertForbidden();
        $this->credentials();
        $this->artisan('ideyra:admin:bootstrap')->assertSuccessful();
        $admin = User::whereHas('roles', fn ($query) => $query->where('name', 'System Administrator'))->sole();
        $admin->status = 'suspended';
        $admin->save();
        $this->actingAs($admin)->get(route('system.dashboard'))->assertForbidden();
        $this->get('/ideyra/admin/bootstrap')->assertNotFound();
    }
}
