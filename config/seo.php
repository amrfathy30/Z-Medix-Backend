<?php

return [
    'public_base_url' => rtrim(
        env('SEO_PUBLIC_BASE_URL', env('FRONTEND_URL', env('APP_URL', 'http://localhost'))),
        '/',
    ),
    'sitemap_cache_ttl' => (int) env('SEO_SITEMAP_CACHE_TTL', 3600),
];
