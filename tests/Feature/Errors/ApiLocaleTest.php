<?php

namespace Tests\Feature\Errors;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ApiLocaleTest extends TestCase
{
    use RefreshDatabase;

    public function test_arabic_validation_is_selected_before_validation_runs(): void
    {
        $this->postJson('/api/v1/auth/login', [], ['Accept-Language' => 'ar-EG'])
            ->assertStatus(422)
            ->assertHeader('Content-Language', 'ar')
            ->assertJsonPath('errors.email.0', 'حقل البريد الإلكتروني مطلوب.');
    }

    public function test_english_validation_does_not_inherit_previous_arabic_request(): void
    {
        $this->postJson('/api/v1/auth/login', [], ['Accept-Language' => 'ar'])
            ->assertStatus(422);

        $this->postJson('/api/v1/auth/login', [], ['Accept-Language' => 'en-US'])
            ->assertStatus(422)
            ->assertHeader('Content-Language', 'en')
            ->assertJsonPath('errors.email.0', 'The email field is required.');
    }

    public function test_api_message_catalogues_have_identical_keys(): void
    {
        $this->assertSame(
            array_keys(require lang_path('en/api.php')),
            array_keys(require lang_path('ar/api.php'))
        );
    }
}
