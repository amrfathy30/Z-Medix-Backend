<?php

namespace Tests\Feature\Marketing\PublicApi;

use App\Enums\Marketing\MarketingPixelPlatform;
use App\Models\GoogleAnalyticsSetting;
use App\Models\MarketingPixel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CoreMarketingParityTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_analytics_exposes_only_public_fields(): void
    {
        GoogleAnalyticsSetting::current()->update([
            'enabled' => true,
            'measurement_id' => 'G-ABC123',
            'api_secret' => 'raw-secret',
            'property_id' => '123456',
            'service_account_json' => '{"private_key":"raw-key"}',
        ]);

        $response = $this->getJson('/api/public/marketing/analytics')->assertOk();
        $this->assertSame(['enabled' => true, 'measurement_id' => 'G-ABC123'], $response->json('data'));
        $this->assertStringNotContainsString('raw-secret', $response->getContent());
        $this->assertStringNotContainsString('raw-key', $response->getContent());
    }

    public function test_public_pixels_expose_only_enabled_ids_and_no_capi_secret(): void
    {
        MarketingPixel::query()->where('platform', MarketingPixelPlatform::Facebook)->update([
            'pixel_id' => '123456789',
            'is_public' => true,
            'meta_capi_access_token' => 'raw-capi-token',
        ]);

        $response = $this->getJson('/api/public/marketing/pixels')->assertOk();
        $this->assertSame(['facebook' => '123456789'], $response->json('data'));
        $this->assertStringNotContainsString('raw-capi-token', $response->getContent());
    }
}
