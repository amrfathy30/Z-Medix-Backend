<?php

namespace Database\Factories;

use App\Enums\Marketing\MarketingPixelPlatform;
use App\Models\MarketingPixel;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<MarketingPixel> */
class MarketingPixelFactory extends Factory
{
    protected $model = MarketingPixel::class;

    public function definition(): array
    {
        return [
            'platform' => MarketingPixelPlatform::Facebook->value,
            'pixel_id' => (string) fake()->numerify('###############'),
            'is_public' => false,
        ];
    }

    public function platform(MarketingPixelPlatform $platform): static
    {
        return $this->state(fn (): array => [
            'platform' => $platform->value,
            'pixel_id' => match ($platform) {
                MarketingPixelPlatform::Facebook => (string) fake()->numerify('###############'),
                MarketingPixelPlatform::X => fake()->regexify('[A-Za-z0-9]{5}'),
                MarketingPixelPlatform::TikTok => fake()->regexify('[A-Z0-9]{20}'),
            },
        ]);
    }

    public function published(): static
    {
        return $this->state(fn (): array => ['is_public' => true]);
    }
}
