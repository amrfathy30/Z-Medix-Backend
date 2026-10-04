<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Nnjeim\World\Models\Country;

abstract class TestCase extends BaseTestCase
{
    /**
     * Create a row in the world package's `countries` table.
     *
     * The table ships empty (WorldSeeder is not part of DatabaseSeeder), so tests
     * that need country reference data create just the rows they use.
     */
    protected function createCountry(string $iso2 = 'EG', string $name = 'Egypt', string $phoneCode = '20'): Country
    {
        return Country::query()->create([
            'iso2' => $iso2,
            'iso3' => mb_strtoupper(mb_substr($name, 0, 3)),
            'name' => $name,
            'phone_code' => $phoneCode,
            'region' => 'Africa',
            'subregion' => 'Northern Africa',
            'status' => 1,
        ]);
    }
}
