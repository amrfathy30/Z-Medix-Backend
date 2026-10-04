<?php

namespace Tests\Feature\Database\Seeders;

use Database\Seeders\DatabaseSeeder;
use Database\Seeders\WorldSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Nnjeim\World\Models\Country;
use Tests\TestCase;

class WorldSeederTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Seed a small, deterministic slice of the dataset: the seeder's behaviour
        // is identical, but the test stays fast.
        config([
            'world.allowed_countries' => ['EG', 'SA'],
            'world.modules' => [
                'states' => false,
                'cities' => false,
                'timezones' => false,
                'currencies' => false,
                'languages' => false,
                'geolocate' => false,
            ],
        ]);
    }

    public function test_it_populates_the_countries_table(): void
    {
        $this->assertSame(0, Country::query()->count());

        $this->seed(WorldSeeder::class);

        $this->assertSame(2, Country::query()->count());
        $this->assertTrue(Country::query()->where('iso2', 'EG')->exists());
        $this->assertTrue(Country::query()->where('iso2', 'SA')->exists());
    }

    public function test_running_it_twice_does_not_duplicate_country_data(): void
    {
        $this->seed(WorldSeeder::class);

        $seeded = Country::query()->orderBy('id')->get(['id', 'iso2']);

        $this->seed(WorldSeeder::class);

        $this->assertEquals(
            $seeded->toArray(),
            Country::query()->orderBy('id')->get(['id', 'iso2'])->toArray(),
            'Re-seeding must not duplicate or renumber existing country rows.',
        );
    }

    public function test_it_leaves_already_seeded_country_ids_untouched(): void
    {
        $existing = $this->createCountry('EG', 'Egypt', '20');

        $this->seed(WorldSeeder::class);

        $existing->refresh();

        $this->assertSame(1, Country::query()->count());
        $this->assertSame('EG', $existing->iso2);
    }

    public function test_database_seeder_leaves_the_environment_ready_for_registration(): void
    {
        $this->seed(DatabaseSeeder::class);

        $egypt = Country::query()->where('iso2', 'EG')->first();

        $this->assertNotNull(
            $egypt,
            'DatabaseSeeder must seed country reference data so registration validation passes.',
        );

        $this->postJson('/api/public/auth/register', [
            'full_name' => 'Mona Saleh',
            'email' => 'mona@example.com',
            'phone' => '+201001234567',
            'country_id' => $egypt->id,
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ])->assertCreated();
    }
}
