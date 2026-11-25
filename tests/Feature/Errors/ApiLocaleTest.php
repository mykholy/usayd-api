<?php

namespace Tests\Feature\Errors;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Arr;
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
        foreach (['api', 'errors', 'identity', 'validation'] as $catalogue) {
            $en = require lang_path("en/{$catalogue}.php");
            $ar = require lang_path("ar/{$catalogue}.php");

            $this->assertSame(array_keys(Arr::dot($en)), array_keys(Arr::dot($ar)), $catalogue);
        }
    }

    public function test_storage_signature_error_is_localized(): void
    {
        $this->getJson('/api/v1/storage/download', ['Accept-Language' => 'ar'])
            ->assertStatus(400)
            ->assertJsonPath('detail', 'معلمات الرابط الموقع المطلوبة غير مكتملة.');

        $this->getJson('/api/v1/storage/download', ['Accept-Language' => 'en'])
            ->assertStatus(400)
            ->assertJsonPath('detail', 'Missing required signed parameters (path, expires, signature).');
    }
}
