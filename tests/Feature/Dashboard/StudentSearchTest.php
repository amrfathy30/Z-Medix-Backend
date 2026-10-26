<?php

namespace Tests\Feature\Dashboard;

use App\Enums\AccountStatus;
use App\Enums\ContentStatus;
use App\Enums\PlanFeature;
use App\Models\Fellowship;
use App\Models\Plan;
use App\Models\ReportCase;
use App\Models\StudentSubscription;
use App\Models\Subject;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class StudentSearchTest extends TestCase
{
    use RefreshDatabase;

    public static function features(): array
    {
        return [
            'subjects' => [[PlanFeature::Library]],
            'fellowships' => [[PlanFeature::QuestionBank]],
            'reports' => [[PlanFeature::CaseReport]],
            'subjects and fellowships' => [[PlanFeature::Library, PlanFeature::QuestionBank]],
            'subjects and reports' => [[PlanFeature::Library, PlanFeature::CaseReport]],
            'all' => [[PlanFeature::Library, PlanFeature::QuestionBank, PlanFeature::CaseReport]],
            'none' => [[]],
        ];
    }

    private function student(array $features = [], string $status = 'active'): User
    {
        $student = User::factory()->create(['status' => AccountStatus::Active]);
        if ($status !== 'none') {
            $plan = Plan::factory()->withFeatures(...$features)->create();
            $subscription = StudentSubscription::factory()->for($student, 'user');
            $subscription = $status === 'expired' ? $subscription->expired($plan) : $subscription->active($plan);
            if ($status === 'cancelled') {
                $subscription = $subscription->cancelled();
            }
            $subscription->create();
        }
        Sanctum::actingAs($student);

        return $student;
    }

    private function content(): void
    {
        Subject::factory()->create(['name' => 'Cardiology', 'status' => ContentStatus::Published]);
        Fellowship::factory()->create(['name' => 'Cardiology Fellowship', 'is_active' => true]);
        ReportCase::factory()->create(['title' => 'Cardiology Case', 'is_active' => true]);
    }

    #[DataProvider('features')]
    public function test_only_entitled_categories_are_queried_and_returned(array $features): void
    {
        $this->content();
        $this->student($features);
        DB::enableQueryLog();
        $response = $this->getJson('/api/student/search?q=cardio')->assertOk()->assertJsonMissingPath('meta');
        $queries = collect(DB::getQueryLog())->pluck('query')->implode("\n");
        DB::disableQueryLog();

        foreach (['subjects' => PlanFeature::Library, 'fellowships' => PlanFeature::QuestionBank, 'report_cases' => PlanFeature::CaseReport] as $key => $feature) {
            $allowed = in_array($feature, $features, true);
            $response->assertJsonCount($allowed ? 1 : 0, 'data.'.$key);
            if (! $allowed) {
                $this->assertStringNotContainsString('from "'.$key.'"', $queries);
            }
        }
    }

    public function test_inactive_or_missing_subscriptions_never_grant_search_access(): void
    {
        $this->content();
        foreach (['none', 'expired', 'cancelled'] as $status) {
            $this->student([PlanFeature::Library, PlanFeature::QuestionBank, PlanFeature::CaseReport], $status);
            $this->getJson('/api/student/search?q=cardio')->assertOk()
                ->assertExactJson(['success' => true, 'message' => 'Success.', 'data' => [
                    'subjects' => [], 'fellowships' => [], 'report_cases' => [],
                ]]);
        }
    }

    public function test_partial_matching_is_case_insensitive_and_names_only(): void
    {
        $this->content();
        $this->student([PlanFeature::Library, PlanFeature::QuestionBank, PlanFeature::CaseReport]);
        ReportCase::factory()->create(['title' => 'Other', 'description' => 'Cardiology', 'source' => 'Cardiology', 'is_active' => true]);
        Subject::factory()->create(['name' => 'Other', 'description' => 'Cardiology', 'status' => ContentStatus::Published]);
        $this->getJson('/api/student/search?q=%20%20cArDiO%20%20')->assertOk()
            ->assertJsonCount(1, 'data.subjects')->assertJsonCount(1, 'data.fellowships')->assertJsonCount(1, 'data.report_cases')
            ->assertJsonStructure(['data' => ['subjects' => [['id', 'name']], 'fellowships' => [['id', 'name']], 'report_cases' => [['id', 'title']]]])
            ->assertJsonMissingPath('data.report_cases.0.description');
    }

    public function test_visibility_and_soft_deletion_rules_are_preserved(): void
    {
        $this->student([PlanFeature::Library, PlanFeature::QuestionBank, PlanFeature::CaseReport]);
        Subject::factory()->create(['name' => 'Cardio draft', 'status' => ContentStatus::Draft]);
        Subject::factory()->create(['name' => 'Cardio deleted', 'status' => ContentStatus::Published])->delete();
        Fellowship::factory()->create(['name' => 'Cardio inactive', 'is_active' => false]);
        Fellowship::factory()->create(['name' => 'Cardio deleted', 'is_active' => true])->delete();
        ReportCase::factory()->create(['title' => 'Cardio inactive', 'is_active' => false]);
        $this->getJson('/api/student/search?q=cardio')->assertOk()
            ->assertJsonPath('data', ['subjects' => [], 'fellowships' => [], 'report_cases' => []]);
    }

    public function test_missing_empty_and_unmatched_terms_return_empty_groups(): void
    {
        $this->content();
        $this->student([PlanFeature::Library, PlanFeature::QuestionBank, PlanFeature::CaseReport]);
        foreach (['', '?q=', '?q=%20%20%20', '?q=does-not-match'] as $query) {
            $this->getJson('/api/student/search'.$query)->assertOk()
                ->assertJsonPath('data', ['subjects' => [], 'fellowships' => [], 'report_cases' => []]);
        }
    }

    public function test_invalid_query_types_and_long_queries_are_rejected(): void
    {
        $this->student();
        foreach (['?q[]=cardio', '?q='.str_repeat('a', 101)] as $query) {
            $this->getJson('/api/student/search'.$query)->assertUnprocessable()->assertJsonValidationErrors('q');
        }
    }

    public function test_wildcards_and_escape_character_are_literal(): void
    {
        $this->student([PlanFeature::Library]);
        Subject::factory()->create(['name' => '100%_! Cardio', 'status' => ContentStatus::Published]);
        Subject::factory()->create(['name' => '100abc Cardio', 'status' => ContentStatus::Published]);
        $this->getJson('/api/student/search?q='.urlencode('%_!'))->assertOk()
            ->assertJsonCount(1, 'data.subjects')->assertJsonPath('data.subjects.0.name', '100%_! Cardio');
    }

    public function test_each_category_is_limited_and_stably_ordered(): void
    {
        $this->student([PlanFeature::Library, PlanFeature::QuestionBank, PlanFeature::CaseReport]);
        Subject::factory()->count(22)->create(['name' => 'Cardio', 'status' => ContentStatus::Published]);
        Fellowship::factory()->count(22)->create(['name' => 'Cardio', 'is_active' => true]);
        ReportCase::factory()->count(22)->create(['title' => 'Cardio', 'is_active' => true]);
        $response = $this->getJson('/api/student/search?q=cardio')->assertOk();
        foreach (['subjects', 'fellowships', 'report_cases'] as $key) {
            $response->assertJsonCount(20, 'data.'.$key);
            $ids = array_column($response->json('data.'.$key), 'id');
            $sorted = $ids;
            sort($sorted);
            $this->assertSame($sorted, $ids);
        }
    }

    public function test_effective_plan_controls_search_after_a_plan_change(): void
    {
        $this->content();
        $student = $this->student([PlanFeature::Library]);
        $subscription = StudentSubscription::where('user_id', $student->id)->firstOrFail();
        $subscription->periods()->create([
            'plan_id' => Plan::factory()->withFeatures(PlanFeature::CaseReport)->create()->id,
            'billing_cycle' => 'monthly', 'amount' => 10, 'currency' => 'USD',
            'starts_at' => now(), 'ends_at' => now()->addMonth(), 'paid_at' => now(),
        ]);
        $this->getJson('/api/student/search?q=cardio')->assertOk()
            ->assertJsonCount(0, 'data.subjects')->assertJsonCount(1, 'data.report_cases');
    }

    public function test_unauthenticated_requests_are_rejected(): void
    {
        $this->getJson('/api/student/search?q=cardio')->assertUnauthorized();
    }
}
