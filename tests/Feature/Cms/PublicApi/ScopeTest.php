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

    public function test_registration_is_only_exposed_on_the_public_auth_prefix(): void
    {
        // Student registration now exists; 422 proves the route is reachable and
        // validated. It must not be mirrored on the admin prefix.
        $this->postJson('/api/public/auth/register', [])->assertStatus(422);
        $this->postJson('/api/admin/auth/register', [])->assertNotFound();
    }

    public function test_no_testimonials_table_endpoint_exists(): void
    {
        $this->getJson('/api/public/testimonials')->assertNotFound();
    }
}
