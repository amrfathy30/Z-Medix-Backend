<?php

namespace App\Http\Controllers;

use App\Models\Page;
use App\Models\Setting;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Spatie\Sitemap\Sitemap;
use Spatie\Sitemap\Tags\Url;

class SitemapController extends Controller
{
    public const ROBOTS_SETTING_KEY = 'seo.robots_txt_body';

    public const DEFAULT_ROBOTS_BODY = "User-agent: *\nAllow: /";

    public const SITEMAP_CACHE_KEY = 'seo.sitemap';

    public function sitemap(): Sitemap
    {
        return Cache::remember(self::SITEMAP_CACHE_KEY, (int) config('seo.sitemap_cache_ttl'), function (): Sitemap {
            $sitemap = Sitemap::create();

            Page::query()->sitemapEligible()->orderBy('sort_order')->orderBy('id')->get()
                ->each(function (Page $page) use ($sitemap): void {
                    if (filled($page->canonical_url)) {
                        $sitemap->add(Url::create($page->canonical_url)->setLastModificationDate($page->updated_at));

                        return;
                    }

                    $alternates = $page->alternateUrls();

                    foreach ($alternates as $url) {
                        $entry = Url::create($url)->setLastModificationDate($page->updated_at);

                        foreach ($alternates as $locale => $alternateUrl) {
                            $entry->addAlternate($alternateUrl, $locale);
                        }

                        $sitemap->add($entry);
                    }
                });

            return $sitemap;
        });
    }

    public function robots(): Response
    {
        $body = trim((string) Setting::query()->where('key', self::ROBOTS_SETTING_KEY)->value('value'));
        $body = $body === '' ? self::DEFAULT_ROBOTS_BODY : $body;

        return response($body."\n\nSitemap: ".self::sitemapUrl()."\n", 200, ['Content-Type' => 'text/plain']);
    }

    public static function sitemapUrl(): string
    {
        return rtrim((string) config('seo.public_base_url'), '/').'/sitemap.xml';
    }
}
