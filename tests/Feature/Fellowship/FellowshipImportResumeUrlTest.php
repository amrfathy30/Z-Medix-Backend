<?php

namespace Tests\Feature\Fellowship;

use App\Enums\FellowshipImportStatus;
use App\Filament\Resources\FellowshipResource;
use App\Filament\Resources\FellowshipResource\Pages\ImportFellowshipQuestions;
use App\Models\FellowshipImport;
use Livewire\Attributes\Url;
use Livewire\Livewire;
use ReflectionProperty;

/**
 * Resuming a staged import from its own URL.
 *
 * The import history offers a Resume action on every staged import, linking to
 * `…/import-questions?import=<id>`. For that link to do anything, the query
 * parameter has to be read back into the page's `$import` property — and a
 * public Livewire property is *not* populated from the query string unless it
 * says so.
 *
 * Without the binding the page mounts with nothing to resume and drops the
 * admin on the upload step, discarding a column mapping and a set of duplicate
 * decisions they had already made. Nothing throws, so only an assertion about
 * the binding itself catches it; driving the component directly cannot, because
 * a test harness passes mount parameters in a way a browser never does.
 */
class FellowshipImportResumeUrlTest extends FellowshipTestCase
{
    public function test_the_import_property_is_bound_to_the_query_string(): void
    {
        $property = new ReflectionProperty(ImportFellowshipQuestions::class, 'import');

        $attributes = $property->getAttributes(Url::class);

        $this->assertNotEmpty(
            $attributes,
            'ImportFellowshipQuestions::$import carries no #[Url] attribute, so the '
                .'Resume action\'s ?import= parameter is ignored and a staged review cannot be reopened.',
        );
    }

    public function test_the_resume_action_links_to_the_import_it_resumes(): void
    {
        $fellowship = $this->fellowship();

        /** @var FellowshipImport $import */
        $import = $fellowship->imports()->create([
            'status' => FellowshipImportStatus::Staged,
            'original_filename' => 'questions.xlsx',
            'stored_path' => 'fellowship-imports/questions.xlsx',
            'headers' => ['Stem'],
        ]);

        $url = FellowshipResource::getUrl(
            'import-questions',
            ['record' => $fellowship->getKey(), 'import' => $import->getKey()],
            panel: 'admin',
        );

        $this->assertStringContainsString(
            'import='.$import->getKey(),
            $url,
            'The resume URL does not carry the import id.',
        );
    }

    /**
     * The whole point of the binding: a staged import reopens on its review,
     * with the mapping it was staged with.
     */
    public function test_a_staged_import_reopens_on_the_review_step(): void
    {
        $this->actingAs($this->superAdmin(), 'admin');

        $fellowship = $this->fellowship();

        $path = $this->uploadCsv(
            ['Stem', 'A', 'B', 'Key', 'Level', 'Area'],
            [['Which antiplatelet?', 'Aspirin', 'Warfarin', 'Aspirin', 'Easy', 'Cardiology']],
            'resume.csv',
        );

        /** @var FellowshipImport $import */
        $import = $fellowship->imports()->create([
            'status' => FellowshipImportStatus::Staged,
            'original_filename' => 'resume.csv',
            'stored_path' => $path,
            'headers' => ['Stem', 'A', 'B', 'Key', 'Level', 'Area'],
            'header_map' => [
                'Stem' => ['target' => 'question_text'],
                'A' => ['target' => 'answer_option'],
                'B' => ['target' => 'answer_option'],
                'Key' => ['target' => 'correct_answer'],
                'Level' => ['target' => 'difficulty'],
                'Area' => ['target' => 'performance_topic'],
            ],
            'total_rows' => 1,
            'valid_rows' => 1,
        ]);

        $component = Livewire::test(ImportFellowshipQuestions::class, [
            'record' => $fellowship->getKey(),
            'import' => $import->getKey(),
        ]);

        $component->assertSet('step', ImportFellowshipQuestions::STEP_REVIEW);
        $component->assertSet('import', $import->getKey());

        $this->assertSame(
            'question_text',
            $component->instance()->headerMap()->toArray()['Stem']['target'] ?? null,
            'The resumed import lost the mapping it was staged with.',
        );
    }
}
