<?php

namespace Database\Factories;

use App\Models\ReportCase;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Http\Testing\File;

/**
 * @extends Factory<ReportCase>
 */
class ReportCaseFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'title' => fake()->unique()->sentence(3),
            'description' => fake()->paragraphs(2, true),
            'source' => fake()->company(),
            'is_active' => true,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
        ]);
    }

    /**
     * A report case with its file already in the report_case_pdf collection.
     */
    public function withPdfFile(string $fileName = 'report-case.pdf'): static
    {
        return $this->afterCreating(function (ReportCase $reportCase) use ($fileName): void {
            $reportCase->addMedia(self::fakePdf($fileName))
                ->toMediaCollection(ReportCase::PDF_COLLECTION);

            $reportCase->unsetRelation('media');
        });
    }

    /**
     * An uploadable PDF whose bytes really do sniff as `application/pdf`, so it
     * passes the collection's own MIME check the way a real upload does.
     * Shares BookFactory's helper so there is one definition of a fake PDF.
     */
    public static function fakePdf(string $fileName = 'report-case.pdf', ?int $reportedKilobytes = null): File
    {
        return BookFactory::fakePdf($fileName, $reportedKilobytes);
    }
}
