<?php

namespace Tests\Feature\Cms\PublicApi;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ScopeTest extends TestCase
{
    use RefreshDatabase;

    public function test_no_lex_packages_endpoint_exists(): void
    {
        $this->getJson('/api/public/lex-packages')->assertNotFound();
    }

    public function test_no_public_lecturers_endpoint_exists(): void
    {
        $this->getJson('/api/public/lecturers')->assertNotFound();
    }

    public function test_no_registration_routes_exist(): void
    {
        $this->postJson('/api/public/auth/register', [])->assertNotFound();
    }

    public function test_no_testimonials_table_endpoint_exists(): void
    {
        $this->getJson('/api/public/testimonials')->assertNotFound();
    }
}
