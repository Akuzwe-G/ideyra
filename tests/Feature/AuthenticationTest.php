<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_registration_is_disabled_and_get_started_is_an_application(): void
    {
        $this->get('/register')->assertNotFound();
        $this->post('/register', ['name' => 'Attempt', 'email' => 'new@example.test', 'password' => 'StrongPassword123'])->assertNotFound();
        $this->assertDatabaseCount('users', 0);
        $this->get('/')->assertOk()->assertSee(route('apply'))->assertDontSee('/register');
    }

    public function test_login_logout_and_guest_auth_boundaries(): void
    {
        $user = User::factory()->create(['password' => 'StrongPassword123']);
        $this->get('/dashboard')->assertRedirectToRoute('login');
        $this->post('/login', ['email' => $user->email, 'password' => 'incorrect'])->assertSessionHasErrors('email');
        $this->assertGuest();
        $this->post('/login', ['email' => $user->email, 'password' => 'StrongPassword123'])->assertRedirectToRoute('dashboard');
        $this->assertAuthenticatedAs($user);
        $this->get('/login')->assertRedirectToRoute('dashboard');
        $this->post('/logout')->assertRedirectToRoute('home');
        $this->assertGuest();
        $this->get('/dashboard')->assertRedirectToRoute('login');
        $this->get('/logout')->assertStatus(405);
    }

    public function test_login_is_rate_limited_without_creating_a_session(): void
    {
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->post('/login', ['email' => 'nobody@example.test', 'password' => 'incorrect'])->assertSessionHasErrors('email');
        }
        $this->post('/login', ['email' => 'nobody@example.test', 'password' => 'incorrect'])->assertStatus(429);
        $this->assertGuest();
    }

    public function test_invalid_email_types_return_validation_errors(): void
    {
        $this->post('/login', ['email' => ['bad'], 'password' => 'anything'])->assertSessionHasErrors('email');
    }

    public function test_pending_or_suspended_accounts_cannot_log_in(): void
    {
        foreach (['pending', 'suspended'] as $status) {
            $user = User::factory()->create(['status' => $status, 'password' => 'StrongPassword123']);
            $this->post('/login', ['email' => $user->email, 'password' => 'StrongPassword123'])->assertSessionHasErrors('email');
            $this->assertGuest();
        }
    }

    public function test_private_account_response_cannot_be_cached(): void
    {
        $response = $this->actingAs(User::factory()->create())->get('/dashboard')->assertOk();
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
    }
}
