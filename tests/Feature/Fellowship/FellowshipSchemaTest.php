<?php

namespace Tests\Feature\Fellowship;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * The module's tables and the constraints the rest of it relies on being there:
 * one value per filter per question, one position per session, and at most one
 * open activity period per session.
 */
class FellowshipSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_fellowship_tables_exist(): void
    {
        foreach ([
            'fellowships',
            'fellowship_filters',
            'fellowship_filter_values',
            'fellowship_questions',
            'fellowship_question_options',
            'fellowship_question_filter_values',
            'fellowship_imports',
            'fellowship_import_rows',
            'fellowship_quiz_sessions',
            'fellowship_session_questions',
            'fellowship_session_periods',
        ] as $table) {
            $this->assertTrue(Schema::hasTable($table), "Missing table [{$table}].");
        }
    }

    public function test_fellowship_carries_its_performance_filter(): void
    {
        $this->assertTrue(Schema::hasColumn('fellowships', 'performance_filter_id'));
    }
}
