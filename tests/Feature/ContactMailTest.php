<?php

namespace Tests\Feature;

use App\Mail\ContactEnquiryMail;
use App\Models\Enquiry;
use App\Support\DeliverContactEnquiry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class ContactMailTest extends TestCase
{
    use RefreshDatabase;

    public function test_recipient_is_independent_from_sender_and_reply_to_is_visitor(): void
    {
        $this->assertSame('akuzwe0@gmail.com', config('ideyra.contact_email'));
        config(['ideyra.contact_email' => 'review@example.test', 'mail.from.address' => 'sender@example.test', 'mail.from.name' => 'Ideyra', 'mail.default' => 'log']);
        Mail::fake();
        $enquiry = Enquiry::factory()->create();
        $this->assertSame('logged', app(DeliverContactEnquiry::class)->send($enquiry));
        Mail::assertSent(ContactEnquiryMail::class, function (ContactEnquiryMail $mail) use ($enquiry): bool {
            $envelope = $mail->envelope();

            return $mail->hasTo('review@example.test')
                && $envelope->from->address === 'sender@example.test'
                && $envelope->replyTo[0]->address === $enquiry->email;
        });
        $this->assertSame('review@example.test', $enquiry->fresh()->mail_recipient);
        $this->assertNotSame('review@example.test', DB::table('enquiries')->value('mail_recipient'));
        $this->assertNotNull($enquiry->fresh()->mail_attempted_at);
        $this->assertNull($enquiry->fresh()->mail_accepted_at);
    }

    public function test_html_escapes_visitor_content_and_plain_text_fallback_preserves_it(): void
    {
        $enquiry = Enquiry::factory()->create(['message' => '<script>alert("untrusted")</script>', 'subject' => "A subject\r\nAnother line"]);
        $mail = new ContactEnquiryMail($enquiry);
        $mail->assertSeeInHtml('&lt;script&gt;', false);
        $mail->assertDontSeeInHtml('<script>', false);
        $mail->assertSeeInText('<script>alert("untrusted")</script>');
        $this->assertStringNotContainsString("\r", $mail->envelope()->subject);
        $this->assertStringNotContainsString("\n", $mail->envelope()->subject);
    }

    public function test_mail_failure_keeps_enquiry_and_records_failure_without_sensitive_error_text(): void
    {
        Mail::shouldReceive('mailer')->once()->andThrow(new \RuntimeException('secret provider detail'));
        $enquiry = Enquiry::factory()->create();
        $this->assertSame('failed', app(DeliverContactEnquiry::class)->send($enquiry));
        $this->assertDatabaseCount('enquiries', 1);
        $this->assertSame('failed', $enquiry->fresh()->mail_status);
        $this->assertNull($enquiry->fresh()->mail_accepted_at);
    }

    public function test_invalid_recipient_does_not_send_and_preserves_storage(): void
    {
        config(['ideyra.contact_email' => 'invalid']);
        Mail::fake();
        $enquiry = Enquiry::factory()->create();
        $this->assertSame('failed', app(DeliverContactEnquiry::class)->send($enquiry));
        Mail::assertNothingSent();
        $this->assertDatabaseCount('enquiries', 1);
    }

    public function test_array_mailer_is_simulated_and_composite_transports_are_unconfirmed(): void
    {
        Mail::fake();
        config(['mail.default' => 'array']);
        $this->assertSame('simulated', app(DeliverContactEnquiry::class)->send(Enquiry::factory()->create()));
        config(['mail.default' => 'failover']);
        $this->assertSame('unconfirmed', app(DeliverContactEnquiry::class)->send(Enquiry::factory()->create()));
    }

    public function test_provider_acceptance_does_not_claim_inbox_delivery(): void
    {
        config(['mail.default' => 'smtp']);
        $pending = new class
        {
            public function to(string $recipient): self
            {
                return $this;
            }

            public function send(ContactEnquiryMail $mail): object
            {
                return new \stdClass;
            }
        };
        Mail::shouldReceive('mailer')->once()->with('smtp')->andReturn($pending);
        $enquiry = Enquiry::factory()->create();
        $this->assertSame('accepted', app(DeliverContactEnquiry::class)->send($enquiry));
        $this->assertNotNull($enquiry->fresh()->mail_accepted_at);
    }

    public function test_controller_reports_mail_failure_without_losing_or_resubmitting_enquiry(): void
    {
        Mail::shouldReceive('mailer')->once()->andThrow(new \RuntimeException('transport unavailable'));
        $this->post('/contact', ['name' => 'Test Student', 'email' => 'student@example.test', 'category' => 'feedback', 'subject' => 'A question', 'message' => 'A sufficiently detailed message about the product.', 'consent' => 1])
            ->assertRedirectToRoute('contact')
            ->assertSessionHas('status', fn ($message) => str_contains($message, 'email notification could not be sent'));
        $this->assertDatabaseCount('enquiries', 1);
        $this->assertSame('failed', Enquiry::sole()->mail_status);
    }

    public function test_rejected_spam_never_sends_email(): void
    {
        Mail::fake();
        $this->post('/contact', ['website' => 'spam'])->assertSessionHasErrors();
        Mail::assertNothingSent();
        $this->assertDatabaseCount('enquiries', 0);
    }
}
