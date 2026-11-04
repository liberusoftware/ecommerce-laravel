<?php

namespace Tests\Feature;

use App\Mail\ContactMessage;
use App\Models\Channel;
use App\Models\ContactRequest;
use App\Models\Store;
use App\Settings\GeneralSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The contact form previously had no action, no method and no @csrf, and no route
 * existed to receive it — submitting reloaded the page. A second, orphaned
 * contact-form component posted to /contact/send, which 404'd. These tests pin the
 * contract now that it actually sends something.
 */
class ContactFormTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // The merchant's address is where the message goes, so it has to exist.
        $this->app->extend(GeneralSettings::class, function (GeneralSettings $settings) {
            $settings->site_email = 'merchant@example.test';

            return $settings;
        });
    }

    /** @return array<string, string> */
    private function validPayload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Ada Lovelace',
            'email' => 'ada@example.test',
            'subject' => 'Where is order 1043?',
            'message' => 'It was due Tuesday and there has been no update since dispatch.',
            'website' => '', // honeypot: humans leave this empty
        ], $overrides);
    }

    #[Test]
    public function the_contact_page_renders(): void
    {
        $this->get(route('contact'))
            ->assertOk()
            ->assertSee('name="message"', false);
    }

    #[Test]
    public function a_valid_message_is_emailed_to_the_merchant(): void
    {
        Mail::fake();

        $this->post(route('contact.send'), $this->validPayload())
            ->assertRedirect(route('contact'))
            ->assertSessionHas('success');

        Mail::assertSent(ContactMessage::class, function (ContactMessage $mail) {
            return $mail->hasTo('merchant@example.test')
                // Reply-To is the sender, so the merchant can just hit reply.
                && $mail->hasReplyTo('ada@example.test')
                && $mail->contactSubject === 'Where is order 1043?';
        });
    }

    /** @return array<string, array{array<string, string>, string}> */
    public static function invalidPayloads(): array
    {
        return [
            'missing name' => [['name' => ''], 'name'],
            'missing email' => [['email' => ''], 'email'],
            'malformed email' => [['email' => 'not-an-address'], 'email'],
            'missing message' => [['message' => ''], 'message'],
            'message too short to act on' => [['message' => 'hi'], 'message'],
        ];
    }

    #[DataProvider('invalidPayloads')]
    #[Test]
    public function invalid_input_is_rejected_and_nothing_is_sent(array $overrides, string $field): void
    {
        Mail::fake();

        $this->post(route('contact.send'), $this->validPayload($overrides))
            ->assertSessionHasErrors($field);

        Mail::assertNothingSent();
    }

    /** The honeypot is the whole anti-spam story, so it needs a test that bites. */
    #[Test]
    public function a_filled_honeypot_is_silently_dropped(): void
    {
        Mail::fake();

        // A bot fills every field it finds, including the hidden one.
        $this->post(route('contact.send'), $this->validPayload(['website' => 'http://spam.example']))
            ->assertRedirect(route('contact'));

        // Silently: the bot gets the same success it would get on a real send, so it
        // learns nothing about why. But no mail leaves.
        Mail::assertNothingSent();
        $this->assertDatabaseCount('contact_requests', 0);
    }

    #[Test]
    public function a_message_is_kept_in_the_stores_inbox(): void
    {
        Mail::fake();

        $this->post(route('contact.send'), $this->validPayload());

        $kept = ContactRequest::query()->withoutGlobalScopes()->sole();
        $this->assertSame(['Ada Lovelace', 'ada@example.test', 'Where is order 1043?'], [$kept->name, $kept->email, $kept->subject]);
        $store = Channel::query()->sole()->store;
        $this->assertSame([$store->id, $store->team_id], [$kept->store_id, $kept->team_id]);
        $this->assertNull($kept->read_at);
    }

    #[Test]
    public function a_mail_failure_still_keeps_the_message_and_thanks_the_sender(): void
    {
        Mail::shouldReceive('to')->andThrow(new \RuntimeException('Connection could not be established with host smtp'));

        $this->post(route('contact.send'), $this->validPayload())
            ->assertRedirect(route('contact'))
            ->assertSessionHas('success');

        $this->assertSame(1, ContactRequest::query()->withoutGlobalScopes()->count());
    }

    #[Test]
    public function the_form_is_rate_limited(): void
    {
        Mail::fake();

        for ($i = 0; $i < 5; $i++) {
            $this->post(route('contact.send'), $this->validPayload());
        }

        $this->post(route('contact.send'), $this->validPayload())->assertStatus(429);
    }

    /** Reply-To is built from user input, so a CRLF payload must not forge headers. */
    #[Test]
    public function header_injection_via_the_email_field_is_rejected(): void
    {
        Mail::fake();

        $this->post(route('contact.send'), $this->validPayload([
            'email' => "ada@example.test\r\nBcc: victim@example.test",
        ]))->assertSessionHasErrors('email');

        Mail::assertNothingSent();
    }

    #[Test]
    public function the_page_shows_contact_details_the_merchant_has_configured(): void
    {
        // The localhost storefront's store; an unset address must not render an empty row.
        Store::query()->firstOrFail()->update([
            'email' => 'merchant@example.test',
            'phone' => '+44 20 7946 0000',
        ]);

        $response = $this->get(route('contact'));

        $response->assertSee('merchant@example.test');
        $response->assertSee('+44 20 7946 0000');
        $response->assertDontSee('Knowledgebase');   // the SaaS template copy is gone
        $response->assertDontSee('Developer APIs');
    }

    #[Test]
    public function a_configured_contact_inbox_receives_the_form_instead_of_the_public_address(): void
    {
        Mail::fake();
        config(['mail.contact_to' => 'inbox@example.test']);

        $this->post(route('contact.send'), $this->validPayload())->assertSessionHas('success');

        Mail::assertSent(ContactMessage::class, fn (ContactMessage $mail) => $mail->hasTo('inbox@example.test') && ! $mail->hasTo('merchant@example.test'));
    }

    #[Test]
    public function nothing_promises_a_response_time(): void
    {
        Mail::fake();

        $this->get(route('contact'))->assertDontSee('working day');
        $this->post(route('contact.send'), $this->validPayload())
            ->assertSessionHas('success', fn (string $message) => ! str_contains($message, 'working day'));
    }
}
