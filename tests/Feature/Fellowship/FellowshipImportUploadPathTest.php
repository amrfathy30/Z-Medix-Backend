<?php

namespace Tests\Feature\Fellowship;

use App\Enums\FellowshipImportMappingTarget;
use App\Enums\FellowshipImportStatus;
use App\Exceptions\Fellowship\FellowshipImportException;
use App\Filament\Resources\FellowshipResource\Pages\ImportFellowshipQuestions;
use App\Models\FellowshipImport;
use App\Services\Fellowship\FellowshipImportStagingService;
use App\Support\Fellowship\ImportFilePath;
use App\Support\Fellowship\ImportHeaderMap;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Which files the import wizard will open.
 *
 * The upload field is Livewire state, so its dehydrated value — a plain string
 * by the time `submitUpload` reads it — is the client's to set. These tests pin
 * down that the wizard reads only a file its own upload step produced, and not
 * whatever path a crafted request happens to name: the import disk also holds
 * `livewire-tmp`, where every pending upload in the application lands, and
 * anything else the installation parks on the same private disk.
 *
 * The check lives at both ends — the wizard that accepts the path and the
 * staging service that opens it — so neither one alone is what stands between an
 * admin who may import questions and the rest of the disk.
 */
class FellowshipImportUploadPathTest extends FellowshipTestCase
{
    /** @return list<string> */
    private function headers(): array
    {
        return ['Stem', 'A', 'B', 'Key', 'Level', 'Area'];
    }

    /** @return list<list<string|null>> */
    private function rows(): array
    {
        return [['Which antiplatelet?', 'Aspirin', 'Warfarin', 'Aspirin', 'Easy', 'Cardiology']];
    }

    /**
     * A file that is readable, parses as a spreadsheet, and is none of the
     * wizard's business.
     */
    private function foreignFile(string $path): string
    {
        Storage::disk('local')->put(
            $path,
            "Stem,A,B,Key,Level,Area\nLeaked?,one,two,one,Easy,Cardiology\n",
        );

        return $path;
    }

    public function test_a_path_outside_the_import_directory_is_refused_as_a_missing_upload(): void
    {
        $this->actingAs($this->superAdmin(), 'admin');
        $fellowship = $this->fellowship();

        $this->foreignFile('livewire-tmp/someone-elses-upload.csv');

        Livewire::test(ImportFellowshipQuestions::class, ['record' => $fellowship->getKey()])
            ->set('uploadData.file', [(string) Str::uuid() => 'livewire-tmp/someone-elses-upload.csv'])
            ->call('submitUpload')
            ->assertHasErrors('uploadData.file')
            ->assertSet('step', ImportFellowshipQuestions::STEP_UPLOAD);

        $this->assertSame(
            0,
            $fellowship->imports()->count(),
            'A file the upload step never produced must not become an import at all.',
        );
    }

    /**
     * The disk root must stay the boundary. Flysystem does detect traversal,
     * but by throwing out of an existence check, and `path()` does not normalise
     * at all — so the wizard has to refuse this itself.
     */
    public function test_a_traversing_path_is_refused_rather_than_escaping_the_disk(): void
    {
        $this->actingAs($this->superAdmin(), 'admin');
        $fellowship = $this->fellowship();

        Livewire::test(ImportFellowshipQuestions::class, ['record' => $fellowship->getKey()])
            ->set('uploadData.file', [(string) Str::uuid() => 'fellowship-imports/../../../../etc/hosts'])
            ->call('submitUpload')
            ->assertHasErrors('uploadData.file')
            ->assertSet('step', ImportFellowshipQuestions::STEP_UPLOAD);

        $this->assertSame(0, $fellowship->imports()->count());
    }

    public function test_a_path_with_an_extension_the_field_does_not_accept_is_refused(): void
    {
        $this->actingAs($this->superAdmin(), 'admin');
        $fellowship = $this->fellowship();

        $this->foreignFile('fellowship-imports/dumped.env');

        Livewire::test(ImportFellowshipQuestions::class, ['record' => $fellowship->getKey()])
            ->set('uploadData.file', [(string) Str::uuid() => 'fellowship-imports/dumped.env'])
            ->call('submitUpload')
            ->assertHasErrors('uploadData.file')
            ->assertSet('step', ImportFellowshipQuestions::STEP_UPLOAD);

        $this->assertSame(0, $fellowship->imports()->count());
    }

    /**
     * The upload the field really did store still goes through, so the guard
     * cannot be mistaken for the wizard simply refusing everything.
     */
    public function test_a_genuine_upload_still_reaches_the_mapping_step(): void
    {
        $this->actingAs($this->superAdmin(), 'admin');
        $fellowship = $this->fellowship();

        Livewire::test(ImportFellowshipQuestions::class, ['record' => $fellowship->getKey()])
            ->set('uploadData.file', [$this->xlsxUpload($this->headers(), $this->rows())])
            ->call('submitUpload')
            ->assertHasNoErrors()
            ->assertSet('step', ImportFellowshipQuestions::STEP_MAPPING);

        $import = $fellowship->imports()->sole();

        $this->assertSame($this->headers(), $import->headers);
        $this->assertStringStartsWith('fellowship-imports/', (string) $import->stored_path);
    }

    /**
     * The second end of the check: an import record already carrying a foreign
     * path is not staged from it either, so the wizard is not the only thing
     * standing in the way.
     */
    public function test_staging_refuses_a_stored_path_outside_the_import_directory(): void
    {
        $fellowship = $this->fellowship();

        $this->foreignFile('livewire-tmp/someone-elses-upload.csv');

        /** @var FellowshipImport $import */
        $import = $fellowship->imports()->create([
            'status' => FellowshipImportStatus::Draft,
            'original_filename' => 'someone-elses-upload.csv',
            'stored_path' => 'livewire-tmp/someone-elses-upload.csv',
            'headers' => $this->headers(),
        ]);

        $this->expectException(FellowshipImportException::class);

        app(FellowshipImportStagingService::class)->stage($import, ImportHeaderMap::fromArray([
            'Stem' => FellowshipImportMappingTarget::QuestionText->value,
            'A' => FellowshipImportMappingTarget::AnswerOption->value,
            'B' => FellowshipImportMappingTarget::AnswerOption->value,
            'Key' => FellowshipImportMappingTarget::CorrectAnswer->value,
            'Level' => FellowshipImportMappingTarget::Difficulty->value,
            'Area' => FellowshipImportMappingTarget::PerformanceTopic->value,
        ]));
    }

    /**
     * Nothing of a foreign file leaks through the mapping step's column samples
     * either — the one place the wizard shows an uploaded file's contents back
     * before anything is staged.
     */
    public function test_column_samples_are_not_read_from_a_foreign_path(): void
    {
        $this->actingAs($this->superAdmin(), 'admin');
        $fellowship = $this->fellowship();

        $this->foreignFile('livewire-tmp/someone-elses-upload.csv');

        /** @var FellowshipImport $import */
        $import = $fellowship->imports()->create([
            'status' => FellowshipImportStatus::Draft,
            'original_filename' => 'someone-elses-upload.csv',
            'stored_path' => 'livewire-tmp/someone-elses-upload.csv',
            'headers' => $this->headers(),
        ]);

        $component = Livewire::test(ImportFellowshipQuestions::class, [
            'record' => $fellowship->getKey(),
            'import' => $import->getKey(),
        ]);

        $this->assertSame([], $component->instance()->sampleValues());
    }

    /**
     * @return list<array{0: mixed}>
     */
    public static function refusedPaths(): array
    {
        return [
            'empty' => [''],
            'whitespace' => ['   '],
            'null' => [null],
            'an unsaved upload' => [['not', 'a', 'string']],
            'rooted' => ['/etc/hosts'],
            'rooted inside the directory' => ['/fellowship-imports/questions.csv'],
            'traversal' => ['fellowship-imports/../livewire-tmp/x.csv'],
            'bare traversal' => ['../questions.csv'],
            'another directory' => ['livewire-tmp/questions.csv'],
            'the directory itself' => ['fellowship-imports/'],
            'nested' => ['fellowship-imports/nested/questions.csv'],
            'no extension' => ['fellowship-imports/questions'],
            'a disallowed extension' => ['fellowship-imports/questions.php'],
            'a null byte' => ["fellowship-imports/questions.csv\0.php"],
            'a backslash' => ['fellowship-imports\\questions.csv'],
        ];
    }

    #[DataProvider('refusedPaths')]
    public function test_the_path_check_refuses(mixed $path): void
    {
        $this->assertNull(ImportFilePath::sanitize($path));
    }

    /**
     * @return list<array{0: string}>
     */
    public static function acceptedPaths(): array
    {
        return [
            'xlsx' => ['fellowship-imports/questions.xlsx'],
            'xls' => ['fellowship-imports/questions.xls'],
            'csv' => ['fellowship-imports/questions.csv'],
            'an uppercase extension' => ['fellowship-imports/questions.XLSX'],
            'a generated name' => ['fellowship-imports/01K7ABCDEFGHJKMNPQRSTVWXYZ.xlsx'],
        ];
    }

    #[DataProvider('acceptedPaths')]
    public function test_the_path_check_accepts(string $path): void
    {
        $this->assertSame($path, ImportFilePath::sanitize($path));
    }
}
