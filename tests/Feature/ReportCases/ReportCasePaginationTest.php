<?php

namespace Tests\Feature\ReportCases;

use App\Models\ReportCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\SubscribesStudents;
use Tests\TestCase;

class ReportCasePaginationTest extends TestCase
{
    use RefreshDatabase, SubscribesStudents;

    #[DataProvider('pageSizes')]
    public function test_page_size_is_bounded_before_querying(int $requested, int $expected): void
    {
        ReportCase::factory()->count(3)->create();
        ReportCase::factory()->inactive()->create();

        Sanctum::actingAs($this->subscribedStudent());

        $this->getJson('/api/student/report-cases?per_page='.$requested)
            ->assertOk()
            ->assertJsonPath('meta.per_page', $expected)
            ->assertJsonPath('meta.total', 3)
            ->assertJsonCount(min(3, $expected), 'data');
    }

    /** @return array<string, array{int, int}> */
    public static function pageSizes(): array
    {
        return [
            'negative' => [-1, 1],
            'zero' => [0, 1],
            'minimum' => [1, 1],
            'maximum' => [50, 50],
            'above maximum' => [1000, 50],
        ];
    }
}
