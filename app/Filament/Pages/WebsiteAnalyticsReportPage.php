<?php

namespace App\Filament\Pages;

use App\Models\GoogleAnalyticsSetting;
use Filament\Pages\Page;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Livewire\WithPagination;
use Spatie\Analytics\Analytics;
use Spatie\Analytics\AnalyticsClient;
use Spatie\Analytics\AnalyticsClientFactory;
use Spatie\Analytics\Period;
use Throwable;

/**
 * Read-only GA4 report: visitors/page views, top referrers and most visited
 * pages over the last 30 days — read FROM GA4's Data API using the service
 * account configured on AnalyticsSettingsPage, the opposite data direction
 * from that page's Measurement Protocol fields (which send events TO GA4).
 *
 * Gated on `reports.view`, not the integrations-settings permission — seeing
 * traffic numbers is a broader capability than editing API credentials.
 */
class WebsiteAnalyticsReportPage extends Page
{
    use WithPagination;

    protected string $view = 'filament.pages.website-analytics-report-page';

    protected static ?string $slug = 'website-analytics';

    protected static ?int $navigationSort = 22;

    private const PERIOD_DAYS = 30;

    private const PER_PAGE = 10;

    public static function getNavigationLabel(): string
    {
        return __('admin.website_analytics.navigation_label');
    }

    public static function getNavigationIcon(): string|\BackedEnum|null
    {
        return 'heroicon-o-chart-bar';
    }

    public static function getNavigationGroup(): ?string
    {
        return __('admin.cms.navigation_group_marketing');
    }

    public static function canAccess(): bool
    {
        return auth('admin')->check() && auth('admin')->user()?->hasPermissionTo('reports.view');
    }

    public function getTitle(): string|Htmlable
    {
        return __('admin.website_analytics.title');
    }

    public function getSubheading(): ?string
    {
        return __('admin.website_analytics.description');
    }

    /**
     * @return array{
     *   configured: bool,
     *   error?: string,
     *   totalVisitors?: int,
     *   totalViews?: int,
     *   byDate?: Collection<int, array{date: mixed, activeUsers: int, screenPageViews: int}>,
     *   topReferrers?: Collection<int, array{pageReferrer: string, screenPageViews: int}>,
     *   topPages?: Collection<int, array{pageTitle: string, fullPageUrl: string, screenPageViews: int}>,
     * }
     */
    public function getAnalyticsData(): array
    {
        $setting = GoogleAnalyticsSetting::current();

        if (! $setting->enabled || blank($setting->property_id) || blank($setting->service_account_json)) {
            return ['configured' => false];
        }

        $credentials = json_decode((string) $setting->service_account_json, true);

        if (! is_array($credentials)) {
            return ['configured' => true, 'error' => 'invalid_credentials'];
        }

        try {
            // Built directly rather than via the Analytics facade/singleton:
            // the package caches raw API responses through whatever cache
            // store the app resolves as its Repository, and this app's
            // CACHE_STORE=database rejects the binary-derived response bytes
            // ("Incorrect string value") when it tries to persist them.
            // An in-memory array store sidesteps that entirely — this page
            // calls the API live on every load anyway, so no cache is lost.
            $googleClient = AnalyticsClientFactory::createAuthenticatedGoogleClient([
                'service_account_credentials_json' => $credentials,
            ]);
            $client = new AnalyticsClient($googleClient, Cache::store('array'));
            $analytics = new Analytics($client, $setting->property_id);

            $period = Period::days(self::PERIOD_DAYS);

            $byDate = $analytics->fetchTotalVisitorsAndPageViews($period, maxResults: self::PERIOD_DAYS + 5)
                ->sortByDesc('date')
                ->values();

            $topReferrers = $analytics->fetchTopReferrers($period, maxResults: 50);
            $topPages = $analytics->fetchMostVisitedPages($period, maxResults: 50);
        } catch (Throwable $exception) {
            report($exception);

            return ['configured' => true, 'error' => 'fetch_failed'];
        }

        return [
            'configured' => true,
            'totalVisitors' => (int) $byDate->sum('activeUsers'),
            'totalViews' => (int) $byDate->sum('screenPageViews'),
            'byDate' => $byDate,
            'topReferrers' => $topReferrers,
            'topPages' => $topPages,
        ];
    }

    /**
     * Slices an already-fetched Collection into a Livewire-aware paginator,
     * keyed by its own named pager — the API itself is called once per page
     * load with a generous maxResults, then paginated in memory rather than
     * re-querying GA4 on every page change.
     *
     * @param  Collection<int, mixed>  $items
     */
    public function paginate(Collection $items, string $pageName, int $perPage = self::PER_PAGE): LengthAwarePaginator
    {
        $page = $this->getPage($pageName);

        return new LengthAwarePaginator(
            $items->forPage($page, $perPage)->values(),
            $items->count(),
            $perPage,
            $page,
            ['pageName' => $pageName],
        );
    }
}
