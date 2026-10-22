<?php

namespace App\Filament\Widgets;

use App\Enums\ContactMessageStatus;
use App\Models\ContactMessage;
use App\Models\Fellowship;
use App\Models\FellowshipQuestion;
use App\Models\StudySession;
use App\Models\User;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class StatsOverviewWidget extends BaseWidget
{
    protected static ?int $sort = 1;

    protected function getStats(): array
    {
        // 7-day user registration sparkline
        $userSparkline = collect(range(6, 0))->map(fn (int $daysAgo): int => User::query()->whereDate('created_at', now()->subDays($daysAgo)->toDateString())->count()
        )->all();

        $newUsersThisMonth = User::query()->where('created_at', '>=', now()->subDays(30))->count();
        $pendingMessagesCount = ContactMessage::query()->where('status', ContactMessageStatus::New)->count();
        $totalFellowships = Fellowship::query()->count();
        $activeSessionsCount = StudySession::query()->whereNull('ended_at')->count();

        return [
            Stat::make(__('admin.dashboard.total_students'), number_format(User::query()->count()))
                ->description(__('admin.dashboard.new_students_30_days', ['count' => $newUsersThisMonth]))
                ->descriptionIcon('heroicon-m-arrow-trending-up')
                ->chart($userSparkline)
                ->color('success'),

            Stat::make(__('admin.dashboard.study_sessions'), number_format(StudySession::query()->count()))
                ->description(__('admin.dashboard.active_study_sessions', ['count' => $activeSessionsCount]))
                ->descriptionIcon('heroicon-m-academic-cap')
                ->color('primary'),

            Stat::make(__('admin.dashboard.fellowship_questions'), number_format(FellowshipQuestion::query()->count()))
                ->description(__('admin.dashboard.fellowships_count', ['count' => $totalFellowships]))
                ->descriptionIcon('heroicon-m-clipboard-document-list')
                ->color('info'),

            Stat::make(__('admin.dashboard.pending_contact'), number_format($pendingMessagesCount))
                ->description($pendingMessagesCount > 0 ? __('admin.dashboard.contact_needs_review') : __('admin.dashboard.contact_all_cleared'))
                ->descriptionIcon('heroicon-m-envelope')
                ->color($pendingMessagesCount > 0 ? 'danger' : 'success')
                ->url('/contact-messages'),
        ];
    }
}
