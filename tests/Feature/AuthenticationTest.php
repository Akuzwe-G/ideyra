<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_registration_hashes_password_and_creates_authenticated_session(): void
    {
        $response = $this->post('/register', ['name' => 'Test Student', 'email' => 'Student@Example.test', 'password' => 'StrongPassword123', 'password_confirmation' => 'StrongPassword123']);
        $response->assertRedirectToRoute('dashboard');
        $user = User::sole();
        $this->assertSame('student@example.test', $user->email);
        $this->assertTrue(Hash::check('StrongPassword123', $user->password));
        $this->assertAuthenticatedAs($user);
        $this->get('/dashboard')->assertOk()->assertSee('Test Student')->assertHeader('X-Robots-Tag', 'noindex, nofollow');
    }

    public function test_registration_validates_duplicates_weak_passwords_and_confirmation(): void
    {
        $user = User::factory()->create();
        $this->post('/register', ['name' => '', 'email' => $user->email, 'password' => 'short', 'password_confirmation' => 'different'])
            ->assertSessionHasErrors(['name', 'email', 'password']);
        $this->assertDatabaseCount('users', 1);
        $this->assertGuest();
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
        $this->get('/register')->assertRedirectToRoute('dashboard');
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

    public function test_invalid_email_types_return_validation_errors_instead_of_server_errors(): void
    {
        $this->post('/login', ['email' => ['bad'], 'password' => 'anything'])->assertSessionHasErrors('email');
    }

    public function test_registration_is_rate_limited(): void
    {
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->post('/register', [])->assertSessionHasErrors();
        }
        $this->post('/register', [])->assertStatus(429);
    }

    public function test_private_account_response_cannot_be_cached(): void
    {
        $this->actingAs(User::factory()->create());
        $response = $this->get('/dashboard')->assertOk();
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
    }
}
