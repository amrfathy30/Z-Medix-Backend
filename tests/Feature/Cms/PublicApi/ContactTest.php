<?php

namespace Tests\Feature\Cms\PublicApi;

use App\Enums\ContactMessageStatus;
use App\Models\ContactMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ContactTest extends TestCase
{
    use RefreshDatabase;

    private string $url = '/api/public/contact';

    private function validPayload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'John Doe',
            'email' => 'john@example.com',
            'message' => 'I need legal advice about a contract dispute.',
        ], $overrides);
    }

    public function test_valid_contact_request_creates_message(): void
    {
        $response = $this->postJson($this->url, $this->validPayload());

        $response->assertStatus(201)
            ->assertJsonPath('success', true);

        $this->assertDatabaseHas('contact_messages', [
            'email' => 'john@example.com',
            'name' => 'John Doe',
        ]);
    }

    public function test_contact_message_defaults_to_new_status(): void
    {
        $this->postJson($this->url, $this->validPayload())->assertStatus(201);

        $message = ContactMessage::first();
        $this->assertSame(ContactMessageStatus::New, $message->status);
    }

    public function test_contact_form_does_not_require_auth(): void
    {
        $this->postJson($this->url, $this->validPayload())->assertStatus(201);
    }

    public function test_invalid_email_fails_validation(): void
    {
        $this->postJson($this->url, $this->validPayload(['email' => 'not-an-email']))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['email']);
    }

    public function test_name_is_required(): void
    {
        $this->postJson($this->url, $this->validPayload(['name' => '']))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['name']);
    }

    public function test_email_is_required(): void
    {
        $this->postJson($this->url, $this->validPayload(['email' => '']))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['email']);
    }

    public function test_message_is_required(): void
    {
        $this->postJson($this->url, $this->validPayload(['message' => '']))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['message']);
    }

    public function test_optional_fields_are_stored(): void
    {
        $this->postJson($this->url, $this->validPayload([
            'phone' => '+1234567890',
            'subject' => 'Contract Dispute',
        ]))->assertStatus(201);

        $this->assertDatabaseHas('contact_messages', [
            'phone' => '+1234567890',
            'subject' => 'Contract Dispute',
        ]);
    }

    public function test_missing_all_fields_fails(): void
    {
        $this->postJson($this->url, [])->assertStatus(422);
    }
}
