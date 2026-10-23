<?php

namespace Tests\Feature;

use App\Models\Enquiry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class ContactTest extends TestCase
{
    use RefreshDatabase;

    private function enquiry(): array
    {
        return ['name' => 'Test Student', 'email' => 'student@example.test', 'category' => 'student', 'subject' => 'A product question', 'message' => 'I would like to learn more about the planned student workspace.', 'consent' => '1', 'website' => ''];
    }

    public function test_enquiries_are_encrypted_stored_and_truthfully_acknowledged_without_email(): void
    {
        Mail::fake();
        $this->post('/contact', $this->enquiry())->assertRedirectToRoute('contact')
            ->assertSessionHas('status', fn ($message) => str_contains($message, 'No email has been sent.'));
        $this->assertDatabaseCount('enquiries', 1);
        $enquiry = Enquiry::sole();
        $this->assertSame('student@example.test', $enquiry->email);
        $this->assertNotNull($enquiry->consented_at);
        $raw = DB::table('enquiries')->first();
        $this->assertNotSame($enquiry->email, $raw->email);
        $this->assertStringNotContainsString('student@example.test', $raw->email);
        $this->assertNotSame($enquiry->message, $raw->message);
        $this->assertArrayNotHasKey('message', $enquiry->toArray());
        Mail::assertNothingSent();
    }

    public function test_contact_validation_and_spam_protection_reject_invalid_submissions(): void
    {
        $this->post('/contact', [])->assertSessionHasErrors(['name', 'email', 'category', 'subject', 'message', 'consent']);
        $this->post('/contact', array_replace($this->enquiry(), ['website' => 'https://spam.example']))->assertSessionHasErrors('website');
        $this->post('/contact', array_replace($this->enquiry(), ['category' => 'admin', 'message' => 'short', 'consent' => '0']))->assertSessionHasErrors(['category', 'message', 'consent']);
        $this->assertDatabaseCount('enquiries', 0);
    }

    public function test_contact_submissions_are_rate_limited(): void
    {
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->post('/contact', $this->enquiry())->assertRedirectToRoute('contact');
        }
        $this->post('/contact', $this->enquiry())->assertStatus(429);
        $this->assertDatabaseCount('enquiries', 5);
    }

    public function test_stored_message_is_escaped_when_redisplaying_invalid_input(): void
    {
        $this->from('/contact')->post('/contact', array_replace($this->enquiry(), ['name' => '', 'message' => '<script>alert("unsafe")</script>']))->assertSessionHasErrors('name');
        $this->get('/contact')->assertOk()->assertDontSee('<script>alert("unsafe")</script>', false);
    }
}
