<?php

namespace App\Filament\Widgets;

use App\Models\User;
use Filament\Widgets\ChartWidget;

class StudentGrowthChart extends ChartWidget
{
    protected static ?int $sort = 2;

    protected int|string|array $columnSpan = [
        'md' => 1,
        'xl' => 1,
    ];

    public function getHeading(): ?string
    {
        return __('admin.dashboard.student_growth_title');
    }

    public function getDescription(): ?string
    {
        return __('admin.dashboard.student_growth_subheading');
    }

    protected function getData(): array
    {
        // 14 days growth
        $days = collect(range(13, 0))->map(function (int $daysAgo): array {
            $date = now()->subDays($daysAgo);
            $dateStr = $date->toDateString();
            $count = User::query()->whereDate('created_at', $dateStr)->count();

            return [
                'label' => $date->format('d M'),
                'count' => $count,
            ];
        });

        return [
            'datasets' => [
                [
                    'label' => __('admin.dashboard.student_growth_dataset'),
                    'data' => $days->pluck('count')->all(),
                    'fill' => 'start',
                    'borderColor' => '#f59e0b',
                    'backgroundColor' => 'rgba(245, 158, 11, 0.1)',
                    'tension' => 0.3,
                ],
            ],
            'labels' => $days->pluck('label')->all(),
        ];
    }

    protected function getType(): string
    {
        return 'line';
    }
}
