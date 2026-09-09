<x-filament-panels::page>
    @php $data = $this->getAnalyticsData(); @endphp

    @if (! $data['configured'])
        <div class="fi-section rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
            <div class="fi-section-content px-6 py-6">
                <p class="text-sm text-gray-500 dark:text-gray-400">
                    {{ __('admin.website_analytics.not_configured') }}
                </p>
                <a href="{{ \App\Filament\Pages\AnalyticsSettingsPage::getUrl() }}"
                    class="fi-btn mt-4 inline-flex items-center justify-center gap-1 rounded-lg bg-primary-600 px-3 py-2 text-sm font-semibold text-white shadow-sm hover:bg-primary-500">
                    {{ __('admin.website_analytics.go_to_settings') }}
                </a>
            </div>
        </div>
    @elseif (isset($data['error']))
        <div class="fi-section rounded-xl bg-danger-50 shadow-sm ring-1 ring-danger-600/20 dark:bg-danger-400/10 dark:ring-danger-400/30">
            <div class="fi-section-content px-6 py-6">
                <p class="text-sm text-danger-700 dark:text-danger-400">
                    {{ __('admin.website_analytics.errors.'.$data['error']) }}
                </p>
            </div>
        </div>
    @else
        <div class="flex items-start gap-2 rounded-lg bg-warning-50 p-4 text-sm text-warning-700 ring-1 ring-warning-600/20 dark:bg-warning-400/10 dark:text-warning-400 dark:ring-warning-400/30">
            <span class="mt-0.5 shrink-0">@svg('heroicon-o-information-circle', 'h-5 w-5')</span>
            <span>{{ __('admin.website_analytics.processing_delay_notice') }}</span>
        </div>

        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
            <div class="fi-section rounded-xl bg-white p-6 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
                <div class="flex items-start justify-between">
                    <div>
                        <p class="text-sm font-medium text-gray-500 dark:text-gray-400">{{ __('admin.website_analytics.stat_visitors') }}</p>
                        <p class="mt-2 text-3xl font-semibold text-gray-950 dark:text-white">{{ number_format($data['totalVisitors']) }}</p>
                        <p class="mt-1 text-xs text-gray-400 dark:text-gray-500">{{ __('admin.website_analytics.last_days') }}</p>
                    </div>
                    <span class="inline-flex shrink-0 items-center justify-center rounded-full bg-gray-100 p-1.5 text-gray-500 dark:bg-white/5 dark:text-gray-400">
                        @svg('heroicon-o-users', 'h-4 w-4')
                    </span>
                </div>

                @php
                    $series = $data['byDate']->sortBy('date')->values();
                    $maxVisitors = max(1, $series->max('activeUsers'));
                    $count = max(1, $series->count() - 1);
                    $points = $series->values()->map(fn ($row, $index) => [
                        'x' => $count === 0 ? 0 : ($index / $count) * 100,
                        'y' => 32 - (($row['activeUsers'] / $maxVisitors) * 30),
                    ]);
                    $polyline = $points->map(fn ($p) => "{$p['x']},{$p['y']}")->implode(' ');
                    $areaPath = 'M0,32 L'.$polyline.' L100,32 Z';
                @endphp
                <div class="mt-4">
                    <svg viewBox="0 0 100 32" preserveAspectRatio="none" class="h-12 w-full overflow-visible">
                        <path d="{{ $areaPath }}" fill="rgb(34 197 94 / 0.15)" stroke="none" />
                        <polyline points="{{ $polyline }}" fill="none" stroke="rgb(34 197 94)" stroke-width="1.5" vector-effect="non-scaling-stroke" stroke-linecap="round" stroke-linejoin="round" />
                    </svg>
                </div>
            </div>

            <div class="fi-section rounded-xl bg-white p-6 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
                <div class="flex items-start justify-between">
                    <div>
                        <p class="text-sm font-medium text-gray-500 dark:text-gray-400">{{ __('admin.website_analytics.stat_views') }}</p>
                        <p class="mt-2 text-3xl font-semibold text-gray-950 dark:text-white">{{ number_format($data['totalViews']) }}</p>
                        <p class="mt-1 text-xs text-gray-400 dark:text-gray-500">{{ __('admin.website_analytics.last_days') }}</p>
                    </div>
                    <span class="inline-flex shrink-0 items-center justify-center rounded-full bg-gray-100 p-1.5 text-gray-500 dark:bg-white/5 dark:text-gray-400">
                        @svg('heroicon-o-eye', 'h-4 w-4')
                    </span>
                </div>
            </div>
        </div>

        <div class="fi-section rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
            <div class="fi-section-header px-6 py-4">
                <h3 class="text-base font-semibold text-gray-950 dark:text-white">{{ __('admin.website_analytics.table_title') }}</h3>
            </div>
            <div class="fi-section-content overflow-x-auto px-6 pb-6">
                @php $datePage = $this->paginate($data['byDate'], 'datePage'); @endphp
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-gray-200 text-start text-xs font-medium text-gray-500 dark:border-white/10 dark:text-gray-400">
                            <th class="py-2 text-start">{{ __('admin.website_analytics.column_date') }}</th>
                            <th class="py-2 text-start">{{ __('admin.website_analytics.column_visitors') }}</th>
                            <th class="py-2 text-start">{{ __('admin.website_analytics.column_views') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($datePage as $row)
                            <tr class="border-b border-gray-100 dark:border-white/5">
                                <td class="py-2 text-gray-950 dark:text-white">{{ \Illuminate\Support\Carbon::parse($row['date'])->toDateString() }}</td>
                                <td class="py-2 text-primary-600 dark:text-primary-400">{{ number_format($row['activeUsers']) }}</td>
                                <td class="py-2 text-gray-500 dark:text-gray-400">{{ number_format($row['screenPageViews']) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
                <div class="mt-4">{{ $datePage->links() }}</div>
            </div>
        </div>

        <div class="grid grid-cols-1 gap-4 lg:grid-cols-2">
            <div class="fi-section rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
                <div class="fi-section-header px-6 py-4">
                    <h3 class="text-base font-semibold text-gray-950 dark:text-white">{{ __('admin.website_analytics.top_referrers_title') }}</h3>
                </div>
                <div class="fi-section-content px-6 pb-6">
                    @php $referrersPage = $this->paginate($data['topReferrers'], 'referrersPage'); @endphp
                    @forelse ($referrersPage as $referrer)
                        <div class="flex items-center justify-between border-b border-gray-100 py-2 text-sm last:border-0 dark:border-white/5">
                            <span class="truncate text-gray-950 dark:text-white">{{ $referrer['pageReferrer'] ?: __('admin.website_analytics.direct_traffic') }}</span>
                            <span class="ms-4 shrink-0 text-gray-500 dark:text-gray-400">{{ number_format($referrer['screenPageViews']) }}</span>
                        </div>
                    @empty
                        <p class="text-sm text-gray-400 dark:text-gray-500">{{ __('admin.website_analytics.no_data') }}</p>
                    @endforelse
                    <div class="mt-4">{{ $referrersPage->links() }}</div>
                </div>
            </div>

            <div class="fi-section rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
                <div class="fi-section-header px-6 py-4">
                    <h3 class="text-base font-semibold text-gray-950 dark:text-white">{{ __('admin.website_analytics.top_pages_title') }}</h3>
                </div>
                <div class="fi-section-content px-6 pb-6">
                    @php $pagesPage = $this->paginate($data['topPages'], 'pagesPage'); @endphp
                    @forelse ($pagesPage as $page)
                        <div class="flex items-center justify-between border-b border-gray-100 py-2 text-sm last:border-0 dark:border-white/5">
                            <span class="truncate text-gray-950 dark:text-white" title="{{ $page['fullPageUrl'] }}">{{ $page['pageTitle'] ?: $page['fullPageUrl'] }}</span>
                            <span class="ms-4 shrink-0 text-gray-500 dark:text-gray-400">{{ number_format($page['screenPageViews']) }}</span>
                        </div>
                    @empty
                        <p class="text-sm text-gray-400 dark:text-gray-500">{{ __('admin.website_analytics.no_data') }}</p>
                    @endforelse
                    <div class="mt-4">{{ $pagesPage->links() }}</div>
                </div>
            </div>
        </div>
    @endif
</x-filament-panels::page>
