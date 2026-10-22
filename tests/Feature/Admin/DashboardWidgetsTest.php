<?php

namespace Tests\Feature\Admin;

use App\Enums\AccountStatus;
use App\Enums\ContactMessageStatus;
use App\Enums\ContentStatus;
use App\Filament\Widgets\LatestContactMessagesWidget;
use App\Filament\Widgets\StatsOverviewWidget;
use App\Filament\Widgets\StudentGrowthChart;
use App\Models\Admin;
use App\Models\ContactMessage;
use App\Models\StudySession;
use App\Models\Subject;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class DashboardWidgetsTest extends TestCase
{
    use RefreshDatabase;

    private Admin $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = Admin::factory()->create([
            'status' => AccountStatus::Active,
        ]);
    }

    public function test_stats_overview_widget_mounts_and_computes_stats(): void
    {
        User::factory()->count(3)->create();
        ContactMessage::create([
            'name' => 'Dr. Ahmed',
            'email' => 'ahmed@example.com',
            'message' => 'Test inquiry message',
            'status' => ContactMessageStatus::New,
        ]);

        $subject = Subject::create([
            'name' => 'Cardiology',
            'status' => ContentStatus::Published,
        ]);

        StudySession::create([
            'user_id' => User::first()->id,
            'subject_id' => $subject->id,
            'started_at' => now(),
        ]);

        Livewire::actingAs($this->admin, 'admin')
            ->test(StatsOverviewWidget::class)
            ->assertSuccessful();
    }

    public function test_student_growth_chart_widget_mounts_successfully(): void
    {
        User::factory()->count(2)->create();

        Livewire::actingAs($this->admin, 'admin')
            ->test(StudentGrowthChart::class)
            ->assertSuccessful();
    }

    public function test_latest_contact_messages_widget_renders_table(): void
    {
        $message = ContactMessage::create([
            'name' => 'Dr. Mona',
            'email' => 'mona@example.com',
            'subject' => 'Fellowship Question',
            'message' => 'Please provide more details',
            'status' => ContactMessageStatus::New,
        ]);

        Livewire::actingAs($this->admin, 'admin')
            ->test(LatestContactMessagesWidget::class)
            ->assertSuccessful()
            ->assertCanSeeTableRecords([$message]);
    }
}
