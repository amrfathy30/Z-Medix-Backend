<?php

namespace Tests\Feature\Fellowship;

use App\Enums\AccountStatus;
use App\Enums\AdminType;
use App\Enums\QuizDifficulty;
use App\Models\Admin;
use App\Models\Fellowship;
use App\Models\FellowshipFilter;
use App\Models\FellowshipFilterValue;
use App\Models\FellowshipQuestion;
use App\Models\User;
use App\Support\Fellowship\ImportHeaderMap;
use Database\Seeders\RolesPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\Concerns\SubscribesStudents;
use Tests\TestCase;

/**
 * Shared setup for the Fellowship module: seeded roles and permissions, an
 * admin to act as, faked disks, and helpers for building a Fellowship with a
 * Performance Topic classification and questions filed under it.
 */
abstract class FellowshipTestCase extends TestCase
{
    use RefreshDatabase, SubscribesStudents;

    /**
     * Built workbooks, keyed by their contents, so one spreadsheet is written
     * once however many tests upload it.
     *
     * @var array<string, string>
     */
    private static array $xlsxBytes = [];

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        Storage::fake('filament_public');
        Storage::fake('local');

        $this->seed(RolesPermissionsSeeder::class);
    }

    protected function superAdmin(): Admin
    {
        $admin = Admin::factory()->create([
            'status' => AccountStatus::Active,
            'type' => AdminType::SuperAdmin,
            'password' => Hash::make('password'),
        ]);
        $admin->assignRole('super_admin');

        return $admin;
    }

    protected function adminWithRole(string $roleName): Admin
    {
        $admin = Admin::factory()->create([
            'status' => AccountStatus::Active,
            'type' => AdminType::Admin,
            'password' => Hash::make('password'),
        ]);
        $admin->assignRole($roleName);

        return $admin;
    }

    /**
     * An admin holding exactly these permissions and no role, so a test can
     * pin down what one permission alone allows.
     *
     * @param  list<string>  $permissions
     */
    protected function adminWithPermissions(array $permissions): Admin
    {
        $admin = Admin::factory()->create([
            'status' => AccountStatus::Active,
            'type' => AdminType::Admin,
            'password' => Hash::make('password'),
        ]);

        $admin->givePermissionTo($permissions);

        return $admin->refresh();
    }

    /**
     * An active student on a plan with every feature: the Fellowship routes are
     * gated by the plan, so these tests need one to reach them.
     */
    protected function student(): User
    {
        return $this->subscribedStudent();
    }

    /**
     * An active Fellowship with its Performance Topic filter designated, which
     * is the state the first confirmed import leaves it in.
     */
    protected function fellowship(string $name = 'Emergency Medicine'): Fellowship
    {
        return Fellowship::factory()
            ->withPerformanceFilter()
            ->create(['name' => $name]);
    }

    /**
     * A question filed under the given Performance Topic, with a valid option
     * set whose first option is the correct one.
     */
    protected function question(
        Fellowship $fellowship,
        string $topic = 'Cardiology',
        QuizDifficulty $difficulty = QuizDifficulty::Easy,
        ?string $text = null,
        int $options = 4,
    ): FellowshipQuestion {
        $value = $this->topicValue($fellowship, $topic);

        /** @var FellowshipQuestion $question */
        $question = FellowshipQuestion::factory()
            ->withOptions($options)
            ->create([
                'fellowship_id' => $fellowship->getKey(),
                'question_text' => $text ?? fake()->unique()->sentence(10).'?',
                'difficulty' => $difficulty,
                'performance_filter_value_id' => $value->getKey(),
            ]);

        return $question->load('options');
    }

    /**
     * The Fellowship's Performance Topic value with this text, created if it
     * has none — the same reuse the importer performs.
     */
    protected function topicValue(Fellowship $fellowship, string $topic): FellowshipFilterValue
    {
        $filter = $fellowship->performanceFilter;

        $this->assertNotNull($filter, 'The fellowship has no Performance Topic filter.');

        return $filter->values()->firstOrCreate(['value' => $topic]);
    }

    /**
     * A dynamic filter on the Fellowship with the given values.
     *
     * @param  list<string>  $values
     */
    protected function dynamicFilter(Fellowship $fellowship, string $name, array $values): FellowshipFilter
    {
        /** @var FellowshipFilter $filter */
        $filter = $fellowship->filters()->create(['name' => $name, 'is_performance' => false]);

        foreach ($values as $value) {
            $filter->values()->create(['value' => $value]);
        }

        return $filter->load('values');
    }

    /**
     * File a question under one dynamic filter value.
     */
    protected function tagQuestion(FellowshipQuestion $question, FellowshipFilter $filter, string $value): void
    {
        $filterValue = $filter->values()->firstOrCreate(['value' => $value]);

        $question->filterValues()->updateOrCreate(
            ['fellowship_filter_id' => $filter->getKey()],
            ['fellowship_filter_value_id' => $filterValue->getKey()],
        );
    }

    /**
     * Write a CSV onto the faked local disk and return its stored path, so an
     * import can be staged from a real file without a binary fixture in the
     * repository. The reader goes through the same Excel package either way.
     *
     * @param  list<string>  $headers
     * @param  list<list<string|null>>  $rows
     */
    protected function uploadCsv(array $headers, array $rows, string $filename = 'questions.csv'): string
    {
        $lines = [$this->csvLine($headers)];

        foreach ($rows as $row) {
            $lines[] = $this->csvLine($row);
        }

        $path = 'fellowship-imports/'.$filename;

        Storage::disk('local')->put($path, implode("\n", $lines)."\n");

        return $path;
    }

    /**
     * A genuine xlsx workbook as a pending upload, so a test can go through the
     * same Livewire upload the admin's browser performs rather than handing the
     * wizard a path that is already stored.
     *
     * @param  list<string>  $headers
     * @param  list<list<string|null>>  $rows
     */
    protected function xlsxUpload(array $headers, array $rows, string $filename = 'questions.xlsx'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($filename, $this->xlsxBytes($headers, $rows));
    }

    /**
     * The workbook from the real smoke test, parked on the import disk.
     *
     * Kept as a fixture rather than rebuilt from arrays: the two bugs it found
     * were both about what a real file actually contains — answers given as
     * option letters, and ninety rows going through confirmation at once.
     */
    protected function storedSmokeTestWorkbook(string $filename = 'smoke-test.xlsx'): string
    {
        $path = 'fellowship-imports/'.$filename;

        Storage::disk('local')->put($path, (string) file_get_contents(
            base_path('tests/Fixtures/Fellowship/01_fellowship_initial_90_questions.xlsx'),
        ));

        return $path;
    }

    /**
     * The same workbook as a path already on the import disk, for a test about
     * what happens *after* the upload. Writing the bytes once and handing over
     * the path keeps the spreadsheet writer out of tests that are not about it.
     *
     * @param  list<string>  $headers
     * @param  list<list<string|null>>  $rows
     */
    protected function storedXlsx(array $headers, array $rows, string $filename = 'questions.xlsx'): string
    {
        $path = 'fellowship-imports/'.$filename;

        Storage::disk('local')->put($path, $this->xlsxBytes($headers, $rows));

        return $path;
    }

    /**
     * A genuine xlsx workbook's bytes, built once per distinct workbook.
     *
     * @param  list<string>  $headers
     * @param  list<list<string|null>>  $rows
     */
    private function xlsxBytes(array $headers, array $rows): string
    {
        $signature = md5(serialize([$headers, $rows]));

        if (isset(self::$xlsxBytes[$signature])) {
            return self::$xlsxBytes[$signature];
        }

        $spreadsheet = new Spreadsheet;
        $spreadsheet->getActiveSheet()->fromArray(array_merge([$headers], $rows), null, 'A1');

        $path = tempnam(sys_get_temp_dir(), 'fellowship-import').'.xlsx';

        (new Xlsx($spreadsheet))->save($path);
        $spreadsheet->disconnectWorksheets();

        $contents = (string) file_get_contents($path);

        unlink($path);

        return self::$xlsxBytes[$signature] = $contents;
    }

    /**
     * Answer the mapping step the way an admin does: name the column that fills
     * each system field, and say what each left-over column becomes.
     *
     * The step is system-field-first, so the state is keyed by field — this is
     * the one place a test needs to know that shape.
     *
     * @param  array<string, string|null>  $systemFields  Field key (`question_text`, `option_1`, …) => the file's column name.
     * @param  array<int, array<string, mixed>>  $extraColumns  Column index in the file => that row's state.
     */
    protected function mapWizard(mixed $component, array $systemFields, array $extraColumns = []): mixed
    {
        foreach ($systemFields as $field => $header) {
            $component->set('mappingData.system.'.$field, $header);
        }

        foreach ($extraColumns as $index => $row) {
            $component->set('mappingData.column_'.$index, $row);
        }

        return $component;
    }

    /**
     * A header map in the shape the wizard stores: header => target, with a
     * filter id or name where the target is a dynamic filter.
     *
     * @param  array<string, array<string, mixed>|string>  $entries
     */
    protected function headerMap(array $entries): ImportHeaderMap
    {
        return ImportHeaderMap::fromArray($entries);
    }

    /**
     * @param  list<string|null>  $values
     */
    private function csvLine(array $values): string
    {
        return implode(',', array_map(
            static function (?string $value): string {
                $value = (string) $value;

                return str_contains($value, ',') || str_contains($value, '"')
                    ? '"'.str_replace('"', '""', $value).'"'
                    : $value;
            },
            $values,
        ));
    }
}
