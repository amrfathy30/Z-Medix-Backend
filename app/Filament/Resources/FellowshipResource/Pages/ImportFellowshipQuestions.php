<?php

namespace App\Filament\Resources\FellowshipResource\Pages;

use App\Enums\FellowshipImportMappingTarget;
use App\Enums\FellowshipImportRowDecision;
use App\Enums\FellowshipImportStatus;
use App\Exceptions\Fellowship\FellowshipImportException;
use App\Filament\Resources\FellowshipResource;
use App\Models\Fellowship;
use App\Models\FellowshipFilter;
use App\Models\FellowshipImport;
use App\Models\FellowshipImportRow;
use App\Services\Fellowship\FellowshipFilterService;
use App\Services\Fellowship\FellowshipImportConfirmationService;
use App\Services\Fellowship\FellowshipImportStagingService;
use App\Support\Fellowship\DuplicateComparison;
use App\Support\Fellowship\ImportFilePath;
use App\Support\Fellowship\ImportHeaderMap;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Url;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Throwable;

/**
 * The question import wizard, as a page of one Fellowship.
 *
 * Because it is a page of the record, the Fellowship it imports into is the one
 * in the URL. There is no Fellowship field anywhere in the wizard, and nothing
 * an admin fills in can redirect the import somewhere else.
 *
 * Three steps:
 *
 *  1. Upload. The workbook is stored and its header row read. Column names are
 *     whatever the file calls them — nothing here knows or assumes any of them.
 *  2. Map. Each header is assigned a system field, an existing filter, a new
 *     filter, or nothing. Confirmation stays disabled until every required
 *     field has a column behind it.
 *  3. Review. Staging has validated each row and matched duplicates, without
 *     writing a single question or filter. Invalid rows are listed with their
 *     reasons and excluded. Every duplicate starts with no decision and must be
 *     given one — individually, or by ticking several and applying one action to
 *     the selection — and each carries a side-by-side of the stored and imported
 *     question to decide from. Confirm stays disabled while any duplicate is
 *     still pending, and the service refuses it independently, so no duplicate
 *     is ever skipped, updated or inserted without the admin saying so.
 *
 * Confirm is safe to press twice: the import is claimed under a lock and each
 * row is stamped as it is applied.
 *
 * @property-read Schema $uploadForm
 * @property-read Schema $mappingForm
 */
class ImportFellowshipQuestions extends Page
{
    // A resource page is not bound to a record by default; this is what makes
    // `{record}` in the route the Fellowship this wizard imports into.
    use InteractsWithRecord;

    protected static string $resource = FellowshipResource::class;

    protected string $view = 'filament.resources.fellowship.import-questions';

    public const STEP_UPLOAD = 'upload';

    public const STEP_MAPPING = 'mapping';

    public const STEP_REVIEW = 'review';

    /**
     * Carried in the URL so the admin can leave a review and come back to it.
     *
     * The binding is what makes that true rather than merely intended: the
     * import history's Resume action links to `?import=<id>`, and without the
     * parameter being read back into this property the page would mount with
     * nothing to resume and drop the admin on the upload step, losing a mapping
     * and a set of duplicate decisions they had already made.
     */
    #[Url(nullable: true)]
    public ?int $import = null;

    public string $step = self::STEP_UPLOAD;

    /** @var array<string, mixed> */
    public ?array $uploadData = [];

    /** @var array<string, mixed> */
    public ?array $mappingData = [];

    /**
     * The admin's per-row duplicate decisions, keyed by import row id. Bound to
     * the review table's selects, and written through to the rows as they
     * change so nothing is lost if the admin navigates away.
     *
     * A row absent from this array, or holding an empty string, is awaiting a
     * decision. There is no default — confirmation stays blocked until every
     * duplicate has one.
     *
     * @var array<int, string>
     */
    public array $decisions = [];

    /**
     * Duplicate row ids ticked for a bulk action, keyed by row id.
     *
     * Kept as a map rather than a list so each checkbox can bind straight to
     * its own key without Livewire reindexing the array under it.
     *
     * @var array<int, bool>
     */
    public array $selected = [];

    public bool $selectAll = false;

    /**
     * The first value each column holds, read once per request.
     *
     * @var array<string, string>|null
     */
    private ?array $sampleValues = null;

    public static function canAccess(array $parameters = []): bool
    {
        return self::canImport();
    }

    /**
     * Importing writes questions, so it answers to the question bank's own
     * create permission rather than to one of its own.
     */
    public static function canImport(): bool
    {
        $admin = auth('admin')->user();

        return $admin !== null && $admin->hasPermissionTo('fellowship_questions.create');
    }

    public function getTitle(): string|Htmlable
    {
        return __('admin.fellowship.import_title');
    }

    public function getBreadcrumbs(): array
    {
        /** @var Fellowship $fellowship */
        $fellowship = $this->getRecord();

        return [
            FellowshipResource::getUrl('index') => __('admin.fellowship.plural_model_label'),
            FellowshipResource::getUrl('view', ['record' => $fellowship]) => $fellowship->name,
            __('admin.fellowship.import_title'),
        ];
    }

    public function mount(int|string $record): void
    {
        $this->record = $this->resolveRecord($record);

        $existing = $this->currentImport();

        // Resuming a review the admin left: the mapping and the staged rows are
        // already stored, so the wizard reopens on the review step.
        if ($existing !== null && $existing->isStaged()) {
            $this->step = self::STEP_REVIEW;
            $this->loadDecisions($existing);
        }

        $this->uploadForm->fill();
        $this->mappingForm->fill($this->headers() === [] ? null : $this->mappingStateFromImport());
    }

    /**
     * Step 1. The upload itself; the Fellowship is not among the fields.
     */
    public function uploadForm(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make(__('admin.fellowship.import_step_upload'))
                    ->description(__('admin.fellowship.import_step_upload_hint'))
                    ->schema([
                        FileUpload::make('file')
                            ->label(__('admin.fellowship.field_import_file'))
                            ->required()
                            ->disk((string) config('fellowship.import.disk', 'local'))
                            ->directory((string) config('fellowship.import.directory', 'fellowship-imports'))
                            ->acceptedFileTypes(config('fellowship.import.mime_types', []))
                            ->maxSize((int) config('fellowship.import.max_file_size_kb', 10 * 1024))
                            ->storeFiles()
                            ->columnSpanFull(),
                    ]),
            ])
            ->statePath('uploadData');
    }

    /**
     * Step 2. The system's fields on the left, the file's columns on the right.
     *
     * The direction matters. The system knows what it needs — a question, two
     * or more options, a correct answer, a difficulty, a Performance Topic — and
     * the admin's job is to say which column of their file carries each. Asking
     * it the other way round, column by column, makes the admin hold the list of
     * required fields in their head and gives them no way to see what is still
     * missing.
     *
     * No column name is ever assumed: every dropdown is built from the headers
     * actually read out of the uploaded workbook.
     *
     * A column may only be used once. Each dropdown disables the headers the
     * other fields have taken, and the columns left over move to the dynamic
     * filter section below, so nothing can be claimed twice and no mapping is
     * silently overwritten.
     */
    public function mappingForm(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make(__('admin.fellowship.import_section_system_fields'))
                    ->description(__('admin.fellowship.import_section_system_fields_hint'))
                    ->schema([
                        Grid::make(2)->schema($this->systemFieldSelects()),
                        Grid::make(2)->schema($this->answerOptionSelects()),
                    ]),

                Section::make(__('admin.fellowship.import_section_extra_columns'))
                    ->description(__('admin.fellowship.import_section_extra_columns_hint'))
                    ->schema($this->extraColumnRows())
                    // Only once the fields the system requires have their
                    // columns: until then every column is still a candidate for
                    // one of them, and offering them all as filters at the same
                    // time is how a column ends up claimed twice. Nothing is
                    // left to decide here if the fields took every column.
                    ->visible(fn (): bool => $this->isMappingComplete() && $this->unclaimedHeaders() !== []),
            ])
            ->statePath('mappingData');
    }

    /**
     * One dropdown per single-value system field.
     *
     * @return list<Select>
     */
    private function systemFieldSelects(): array
    {
        $selects = [];

        foreach (FellowshipImportMappingTarget::systemFields() as $target) {
            $selects[] = $this->headerSelect(
                key: $target->value,
                label: $this->targetLabel($target),
                required: $target->isRequired(),
                default: $this->defaultHeaderFor($target),
            );
        }

        return $selects;
    }

    /**
     * The numbered answer-option slots: the first two required, the rest there
     * for a file that carries more.
     *
     * Each slot is followed by the column explaining that option, so the two
     * sit side by side on one row of the grid and an admin cannot lose track of
     * which explanation belongs to which answer. The explanation is always
     * optional: a file that carries none imports exactly as it did before.
     *
     * @return list<Select>
     */
    private function answerOptionSelects(): array
    {
        $selects = [];

        for ($slot = 1; $slot <= $this->answerOptionSlotCount(); $slot++) {
            $selects[] = $this->headerSelect(
                key: $this->optionKey($slot),
                label: __('admin.fellowship.field_import_answer_option_number', ['number' => $slot]),
                required: $slot <= ImportHeaderMap::MINIMUM_OPTION_COLUMNS,
            );

            $selects[] = $this->headerSelect(
                key: $this->optionExplanationKey($slot),
                label: __('admin.fellowship.field_import_answer_option_explanation_number', ['number' => $slot]),
                required: false,
            );
        }

        return $selects;
    }

    /**
     * One system field's column dropdown.
     *
     * The options are the file's own headers. A header another field has
     * already taken is disabled rather than hidden, so the admin can see it is
     * spoken for instead of wondering where it went.
     */
    private function headerSelect(string $key, string $label, bool $required, ?string $default = null): Select
    {
        return Select::make('system.'.$key)
            ->label($label)
            ->options($this->headerOptions())
            ->default($default)
            ->required($required)
            ->live()
            ->searchable()
            ->native(false)
            ->placeholder(__('admin.fellowship.import_column_unmapped'))
            // Both halves of "a column is used once". Disabling is what the
            // admin sees; Filament refuses a disabled option on submit too, so
            // a stale or hand-made request cannot slip a second claim past.
            ->disableOptionWhen(fn (string $value): bool => in_array($value, $this->headersClaimedBySystemFields(except: $key), true))
            ->validationMessages(['in' => __('admin.fellowship.import_column_not_available')])
            // The first value the column actually holds, so a mapping can be
            // checked against the file rather than taken on trust.
            ->helperText(fn (Get $get): ?string => $this->sampleFor((string) $get('system.'.$key)));
    }

    /**
     * One row per column the system fields have not taken: create a filter from
     * it, feed an existing one, or leave it out.
     *
     * A row is generated for every header and hidden once a system field claims
     * that header, because a schema is built once per request while the
     * admin's choices keep moving.
     *
     * @return list<Grid>
     */
    private function extraColumnRows(): array
    {
        /** @var Fellowship $fellowship */
        $fellowship = $this->getRecord();

        // The Performance Topic is a system field with its own dropdown above,
        // and a Fellowship has exactly one of it, so its filter is not on offer
        // here: a second column feeding it would fork the classification.
        $existingFilters = $fellowship->dynamicFilters()->pluck('name', 'id')->all();

        $rows = [];

        foreach ($this->headers() as $index => $header) {
            $key = $this->headerKey($index);

            $rows[] = Grid::make(3)
                ->schema([
                    Placeholder::make($key.'.header')
                        ->label(__('admin.fellowship.field_import_column'))
                        ->content(fn (): string => $header.$this->sampleSuffixFor($header)),

                    Select::make($key.'.target')
                        ->label(__('admin.fellowship.field_import_mapped_to'))
                        ->options($this->extraColumnTargetOptions())
                        ->default($this->defaultTargetFor($header)->value)
                        ->selectablePlaceholder(false)
                        ->required()
                        ->native(false)
                        ->live(),

                    Select::make($key.'.filter_id')
                        ->label(__('admin.fellowship.field_import_existing_filter'))
                        ->options($existingFilters)
                        ->default($this->defaultFilterIdFor($header))
                        ->native(false)
                        ->required()
                        ->visible(fn (Get $get): bool => $get($key.'.target') === FellowshipImportMappingTarget::ExistingFilter->value),

                    TextInput::make($key.'.filter_name')
                        ->label(__('admin.fellowship.field_import_new_filter_name'))
                        ->default($header)
                        ->maxLength(255)
                        ->required()
                        ->visible(fn (Get $get): bool => $get($key.'.target') === FellowshipImportMappingTarget::NewFilter->value),
                ])
                ->visible(fn (): bool => ! in_array($header, $this->headersClaimedBySystemFields(), true));
        }

        return $rows;
    }

    /**
     * Store the upload, open a draft import and read the header row.
     */
    public function submitUpload(): void
    {
        // The name the admin recognises, taken while the upload is still
        // pending: once it is stored the file carries a generated name.
        $uploadedFilename = $this->pendingUploadFilename();

        // `getState()` is what moves a pending upload onto the import disk and
        // leaves its stored path in state. Validating alone does not: the field
        // would still be holding Livewire's TemporaryUploadedFile, which is why
        // the raw property must never be read here.
        $state = $this->uploadForm->getState();

        $disk = Storage::disk((string) config('fellowship.import.disk', 'local'));
        $path = $this->storedUploadPath($state['file'] ?? null);

        // Nothing readable behind the field: either the upload never reached
        // the disk, or a temporary upload expired and was swept before this
        // request. Either way the admin has to upload again, so it is said on
        // the field rather than left to fail further in.
        if ($path === null || ! $disk->exists($path)) {
            Notification::make()
                ->danger()
                ->title(__('admin.fellowship.import_upload_missing'))
                ->send();

            throw ValidationException::withMessages([
                'uploadData.file' => __('admin.fellowship.import_upload_missing'),
            ]);
        }

        /** @var Fellowship $fellowship */
        $fellowship = $this->getRecord();

        try {
            $headers = app(FellowshipImportStagingService::class)->headers($disk->path($path));

            $this->assertHeadersAreMappable($headers);
        } catch (FellowshipImportException $exception) {
            Notification::make()->danger()->title($exception->getMessage())->send();

            return;
        }

        /** @var FellowshipImport $import */
        $import = $fellowship->imports()->create([
            'admin_id' => auth('admin')->id(),
            'status' => FellowshipImportStatus::Draft,
            'original_filename' => $uploadedFilename ?? basename($path),
            'stored_path' => $path,
            'headers' => $headers,
        ]);

        $this->import = $import->getKey();
        $this->step = self::STEP_MAPPING;
        $this->rebuildMappingForm();
    }

    /**
     * Refuse a header row the admin cannot map onto anything.
     *
     * The reader copes with an untidy header row on its own: a blank column
     * keeps a placeholder so the columns after it do not shift, and a repeated
     * name is suffixed rather than collapsed into the column before it. Both
     * leave something the admin can look at and decide about.
     *
     * What it cannot cope with is a file too narrow to hold a question. That is
     * usually a file whose first row is not its column names at all, and saying
     * so here is far clearer than a mapping step with too little to choose from.
     *
     * @param  list<string>  $headers
     *
     * @throws FellowshipImportException
     */
    private function assertHeadersAreMappable(array $headers): void
    {
        // A question needs its text, a correct answer, a difficulty, a
        // Performance Topic and two options: six distinct columns at least.
        $minimumColumns = count(FellowshipImportMappingTarget::required())
            - 1
            + ImportHeaderMap::MINIMUM_OPTION_COLUMNS;

        if (count($headers) < $minimumColumns) {
            throw FellowshipImportException::tooFewColumns($minimumColumns, count($headers));
        }
    }

    /**
     * Apply the mapping and stage the import.
     *
     * Still nothing is written into the Fellowship: staging only records what it
     * made of each row.
     */
    public function submitMapping(): void
    {
        $state = $this->mappingForm->getState();

        $import = $this->currentImport();

        if ($import === null) {
            $this->step = self::STEP_UPLOAD;

            return;
        }

        try {
            app(FellowshipImportStagingService::class)->stage($import, $this->headerMapFromForm($state));
        } catch (FellowshipImportException $exception) {
            Notification::make()->danger()->title($exception->getMessage())->send();

            return;
        }

        $this->step = self::STEP_REVIEW;
        $this->loadDecisions($import->refresh());

        Notification::make()
            ->success()
            ->title(__('admin.fellowship.import_staged_title'))
            ->body(__('admin.fellowship.import_staged_body', [
                'valid' => $import->valid_rows,
                'duplicate' => $import->duplicate_rows,
                'invalid' => $import->invalid_rows,
            ]))
            ->send();
    }

    /**
     * Record one duplicate row's decision as the admin makes it.
     */
    public function updatedDecisions(mixed $value, string $key): void
    {
        $rowId = (int) $key;
        $row = $this->rowFor($rowId);

        if ($row === null) {
            return;
        }

        // Back to the blank option: the row returns to awaiting a decision,
        // which keeps confirmation blocked rather than falling back to a
        // default the admin did not choose.
        if ((string) $value === '') {
            app(FellowshipImportConfirmationService::class)->undecide($row);

            return;
        }

        $decision = FellowshipImportRowDecision::tryFrom((string) $value);

        if ($decision === null) {
            $this->decisions[$rowId] = '';

            return;
        }

        try {
            app(FellowshipImportConfirmationService::class)->decide($row, $decision);
        } catch (FellowshipImportException $exception) {
            $this->decisions[$rowId] = $row->refresh()->decision?->value ?? '';

            Notification::make()->danger()->title($exception->getMessage())->send();
        }
    }

    /**
     * Tick or untick every selectable duplicate row.
     */
    public function updatedSelectAll(mixed $value): void
    {
        if (! (bool) $value) {
            $this->selected = [];

            return;
        }

        $this->selected = $this->duplicateRows()
            ->mapWithKeys(fn (FellowshipImportRow $row): array => [$row->getKey() => true])
            ->all();
    }

    /**
     * Apply one decision to every ticked row.
     *
     * A ticked row the decision does not suit — Update Existing against a
     * within-file duplicate, say — is left awaiting a decision rather than
     * given something else, and the notification says how many were left.
     */
    public function applyBulkDecision(string $decision): void
    {
        $case = FellowshipImportRowDecision::tryFrom($decision);
        $rows = $this->selectedRows();

        if ($case === null || $rows->isEmpty()) {
            Notification::make()->warning()->title(__('admin.fellowship.bulk_no_rows_selected'))->send();

            return;
        }

        ['applied' => $applied, 'skipped' => $skipped] = app(FellowshipImportConfirmationService::class)
            ->decideMany($rows, $case);

        $import = $this->currentImport();

        if ($import !== null) {
            $this->loadDecisions($import);
        }

        $notification = Notification::make()
            ->title(__('admin.fellowship.bulk_applied', ['count' => $applied]));

        $skipped > 0
            ? $notification->warning()->body(__('admin.fellowship.bulk_not_applicable', ['count' => $skipped]))
            : $notification->success();

        $notification->send();
    }

    public function clearSelection(): void
    {
        $this->selected = [];
        $this->selectAll = false;
    }

    /**
     * The ticked rows, with the matched question loaded so each row can be
     * checked for whether Update Existing is on offer without a query apiece.
     *
     * @return Collection<int, FellowshipImportRow>
     */
    public function selectedRows(): Collection
    {
        $ids = array_keys(array_filter($this->selected));

        if ($ids === []) {
            return FellowshipImportRow::query()->whereRaw('1 = 0')->get();
        }

        return $this->duplicateRows()->whereIn('id', $ids);
    }

    public function selectedCount(): int
    {
        return count(array_filter($this->selected));
    }

    /**
     * How many duplicates the admin still has to decide. Confirmation is
     * blocked while this is above zero.
     */
    public function unresolvedDuplicatesCount(): int
    {
        return $this->currentImport()?->unresolvedDuplicatesCount() ?? 0;
    }

    public function canConfirm(): bool
    {
        $import = $this->currentImport();

        return $import !== null
            && $import->isStaged()
            && $this->headerMap()->isComplete()
            && ! $import->hasUnresolvedDuplicates();
    }

    /**
     * The decisions a bulk action may offer, given what is ticked.
     *
     * Update Existing disappears once the selection contains a row it cannot
     * apply to, so the button never promises something it would silently skip.
     *
     * @return array<string, string>
     */
    public function bulkDecisionOptions(): array
    {
        $rows = $this->selectedRows();

        $options = [];

        foreach (FellowshipImportRowDecision::cases() as $decision) {
            if ($rows->isNotEmpty() && $rows->every(fn (FellowshipImportRow $row): bool => $row->allows($decision))) {
                $options[$decision->value] = $this->decisionLabel($decision);
            }
        }

        return $options;
    }

    /**
     * Apply the import. The only step that writes.
     */
    public function confirm(): void
    {
        $import = $this->currentImport();

        if ($import === null || ! $import->isStaged()) {
            Notification::make()->danger()->title(__('admin.fellowship.import_not_staged'))->send();

            return;
        }

        if (! $this->headerMap()->isComplete()) {
            Notification::make()->danger()->title(__('admin.fellowship.import_mapping_incomplete'))->send();

            return;
        }

        $unresolved = $import->unresolvedDuplicatesCount();

        if ($unresolved > 0) {
            Notification::make()
                ->danger()
                ->title(__('admin.fellowship.import_duplicates_undecided_title'))
                ->body(__('admin.fellowship.import_duplicates_undecided_body', ['count' => $unresolved]))
                ->send();

            return;
        }

        try {
            $import = app(FellowshipImportConfirmationService::class)->confirm($import);
        } catch (Throwable $exception) {
            Notification::make()->danger()->title(__('admin.fellowship.import_failed'))->body($exception->getMessage())->send();

            return;
        }

        Notification::make()
            ->success()
            ->title(__('admin.fellowship.import_completed_title'))
            ->body(__('admin.fellowship.import_completed_body', [
                'created' => $import->created_questions,
                'updated' => $import->updated_questions,
                'skipped' => $import->skipped_questions,
            ]))
            ->send();

        $this->redirect(FellowshipResource::getUrl('view', ['record' => $this->getRecord()]));
    }

    /**
     * Back to mapping, keeping the upload: the staged rows are replaced when the
     * new mapping is staged, so the admin can re-read the same file differently.
     */
    public function backToMapping(): void
    {
        $this->step = self::STEP_MAPPING;
        $this->rebuildMappingForm($this->mappingStateFromImport());
    }

    public function startOver(): void
    {
        $this->import = null;
        $this->decisions = [];
        $this->step = self::STEP_UPLOAD;
        $this->uploadForm->fill();
    }

    public function currentImport(): ?FellowshipImport
    {
        if ($this->import === null) {
            return null;
        }

        /** @var FellowshipImport|null $import */
        $import = FellowshipImport::query()
            ->where('fellowship_id', $this->getRecord()->getKey())
            ->whereKey($this->import)
            ->first();

        return $import;
    }

    /**
     * The uploaded file's column names.
     *
     * @return list<string>
     */
    public function headers(): array
    {
        $import = $this->currentImport();

        if ($import !== null && is_array($import->headers)) {
            return array_values(array_map('strval', $import->headers));
        }

        return [];
    }

    public function headerMap(): ImportHeaderMap
    {
        return ImportHeaderMap::fromArray($this->currentImport()?->header_map ?? []);
    }

    /**
     * Rows that need the admin's attention, in file order.
     *
     * @return Collection<int, FellowshipImportRow>
     */
    public function duplicateRows(): Collection
    {
        $import = $this->currentImport();

        if ($import === null) {
            return FellowshipImportRow::query()->whereRaw('1 = 0')->get();
        }

        return $import->duplicateRows()->with('duplicateOfQuestion.options')->get();
    }

    /**
     * Whether any duplicate at all needs reviewing, so the review screen can
     * leave the section out when there is nothing to decide.
     */
    public function hasDuplicates(): bool
    {
        return $this->duplicateRows()->isNotEmpty();
    }

    /**
     * @return Collection<int, FellowshipImportRow>
     */
    public function invalidRows(): Collection
    {
        $import = $this->currentImport();

        if ($import === null) {
            return FellowshipImportRow::query()->whereRaw('1 = 0')->get();
        }

        return $import->invalidRows()->get();
    }

    /**
     * The side-by-side for one duplicate row.
     */
    public function comparisonFor(FellowshipImportRow $row): DuplicateComparison
    {
        $row->setRelation('import', $this->currentImport());

        return DuplicateComparison::build($row, $row->duplicateOfQuestion);
    }

    /**
     * The decisions one row may be given, led by a blank "pending" option.
     *
     * The blank option is what makes "no decision yet" a state the admin can
     * see and return to, rather than something inferred from a default. Update
     * Existing is absent for a row duplicating an earlier row of the same file,
     * and for one whose matched question has since been deleted.
     *
     * @return array<string, string>
     */
    public function decisionOptions(FellowshipImportRow $row): array
    {
        $options = ['' => __('admin.fellowship.decision_pending')];

        foreach ($row->availableDecisions() as $decision) {
            $options[$decision->value] = $this->decisionLabel($decision);
        }

        return $options;
    }

    public function decisionLabel(FellowshipImportRowDecision $decision): string
    {
        return match ($decision) {
            FellowshipImportRowDecision::Skip => __('admin.fellowship.decision_skip'),
            FellowshipImportRowDecision::UpdateExisting => __('admin.fellowship.decision_update'),
            FellowshipImportRowDecision::KeepBoth => __('admin.fellowship.decision_keep_both'),
        };
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('backToFellowship')
                ->label(__('admin.fellowship.action_back_to_fellowship'))
                ->icon(Heroicon::OutlinedArrowUturnLeft)
                ->color('gray')
                ->url(fn (): string => FellowshipResource::getUrl('view', ['record' => $this->getRecord()])),
        ];
    }

    /**
     * What a left-over column may become. System fields are not among them:
     * those are chosen from their own dropdowns, which is what keeps one
     * column from being claimed twice.
     *
     * @return array<string, string>
     */
    private function extraColumnTargetOptions(): array
    {
        return [
            FellowshipImportMappingTarget::Ignore->value => __('admin.fellowship.target_ignore'),
            FellowshipImportMappingTarget::NewFilter->value => __('admin.fellowship.target_new_filter'),
            FellowshipImportMappingTarget::ExistingFilter->value => __('admin.fellowship.target_existing_filter'),
        ];
    }

    public function targetLabel(FellowshipImportMappingTarget $target): string
    {
        return __('admin.fellowship.target_'.$target->value);
    }

    /**
     * The file's columns as dropdown options, keyed by the header itself.
     *
     * @return array<string, string>
     */
    public function headerOptions(): array
    {
        $options = [];

        foreach ($this->headers() as $header) {
            $options[$header] = $header;
        }

        return $options;
    }

    /**
     * Columns taken by a system field, so no other dropdown offers them and the
     * dynamic filter section leaves them out.
     *
     * @return list<string>
     */
    public function headersClaimedBySystemFields(?string $except = null): array
    {
        $system = $this->mappingData['system'] ?? [];

        if (! is_array($system)) {
            return [];
        }

        $claimed = [];

        foreach ($system as $key => $header) {
            if ($key === $except || ! is_string($header) || $header === '') {
                continue;
            }

            $claimed[] = $header;
        }

        return array_values(array_unique($claimed));
    }

    /**
     * The required fields still without a column, by their labels, so the step
     * can say what is missing instead of only refusing to continue.
     *
     * Read from the live form state rather than from the stored map: it is the
     * mapping in front of the admin that decides whether they may go on.
     *
     * @return list<string>
     */
    public function unmappedRequiredFields(): array
    {
        $system = is_array($this->mappingData['system'] ?? null) ? $this->mappingData['system'] : [];

        $chosen = static fn (string $key): bool => is_string($system[$key] ?? null) && $system[$key] !== '';

        $missing = [];

        foreach (FellowshipImportMappingTarget::systemFields() as $target) {
            if ($target->isRequired() && ! $chosen($target->value)) {
                $missing[] = $this->targetLabel($target);
            }
        }

        $mappedOptions = 0;

        for ($slot = 1; $slot <= $this->answerOptionSlotCount(); $slot++) {
            if ($chosen($this->optionKey($slot))) {
                $mappedOptions++;
            }
        }

        if ($mappedOptions < ImportHeaderMap::MINIMUM_OPTION_COLUMNS) {
            $missing[] = __('admin.fellowship.field_import_answer_option_number', [
                'number' => $mappedOptions + 1,
            ]);
        }

        return $missing;
    }

    /**
     * Whether the mapping in front of the admin may be staged. This is what
     * keeps the step from being left before it is usable; the import object
     * refuses an incomplete map independently, so neither stands alone.
     */
    public function isMappingComplete(): bool
    {
        return $this->unmappedRequiredFields() === [];
    }

    /**
     * Columns no system field has taken — the ones the dynamic filter section
     * is about.
     *
     * @return list<string>
     */
    public function unclaimedHeaders(): array
    {
        $claimed = $this->headersClaimedBySystemFields();

        return array_values(array_filter(
            $this->headers(),
            static fn (string $header): bool => ! in_array($header, $claimed, true),
        ));
    }

    /**
     * How many answer-option slots to offer: never fewer than the two that are
     * required, never more spare slots than the file has columns to fill them,
     * and never more than the configured ceiling.
     */
    public function answerOptionSlotCount(): int
    {
        // The required single-value fields each take a column of their own,
        // leaving the rest available to be options.
        $reservedForOtherRequiredFields = count(array_filter(
            FellowshipImportMappingTarget::systemFields(),
            static fn (FellowshipImportMappingTarget $target): bool => $target->isRequired(),
        ));

        $spare = count($this->headers()) - $reservedForOtherRequiredFields;
        $ceiling = max(
            ImportHeaderMap::MINIMUM_OPTION_COLUMNS,
            (int) config('fellowship.import.max_answer_option_columns', 10),
        );

        return max(ImportHeaderMap::MINIMUM_OPTION_COLUMNS, min($spare, $ceiling));
    }

    /**
     * A column's first non-empty value, read from the uploaded workbook, so the
     * admin can tell at a glance whether they picked the column they meant.
     *
     * Kept to one read per request: the sample comes from the same parse the
     * rest of the wizard uses.
     *
     * @return array<string, string>
     */
    public function sampleValues(): array
    {
        if ($this->sampleValues !== null) {
            return $this->sampleValues;
        }

        $import = $this->currentImport();
        $disk = Storage::disk((string) config('fellowship.import.disk', 'local'));

        // Checked before it reaches the disk, the same way the upload step
        // checks it: a stored path is only read if it is one this module's own
        // uploads produce.
        $path = ImportFilePath::sanitize($import?->stored_path);

        if ($path === null || ! $disk->exists($path)) {
            return $this->sampleValues = [];
        }

        try {
            $rows = app(FellowshipImportStagingService::class)->preview($disk->path($path));
        } catch (FellowshipImportException) {
            return $this->sampleValues = [];
        }

        $samples = [];

        foreach ($this->headers() as $header) {
            foreach ($rows as $row) {
                $value = trim((string) ($row[$header] ?? ''));

                if ($value !== '') {
                    $samples[$header] = Str::limit($value, 60);

                    break;
                }
            }
        }

        return $this->sampleValues = $samples;
    }

    /**
     * The sample for one column, phrased for a field's helper text.
     */
    public function sampleFor(string $header): ?string
    {
        $sample = $this->sampleValues()[$header] ?? null;

        return $sample === null
            ? null
            : __('admin.fellowship.import_column_sample', ['value' => $sample]);
    }

    /**
     * The sample appended to a column's own name, for the left-over column rows
     * where the name and its sample share one line.
     */
    private function sampleSuffixFor(string $header): string
    {
        $sample = $this->sampleValues()[$header] ?? null;

        return $sample === null ? '' : ' — '.$sample;
    }

    /**
     * What a left-over column is pre-selected as.
     *
     * A column whose name matches a dynamic filter the Fellowship already has
     * is offered as that filter, which is the single most useful default: it is
     * how a second import of the same shape reuses rather than forks.
     * Everything else starts as Ignore, so nothing is mapped by guesswork.
     */
    private function defaultTargetFor(string $header): FellowshipImportMappingTarget
    {
        return $this->existingDynamicFilterFor($header) === null
            ? FellowshipImportMappingTarget::Ignore
            : FellowshipImportMappingTarget::ExistingFilter;
    }

    private function defaultFilterIdFor(string $header): ?int
    {
        return $this->existingDynamicFilterFor($header)?->getKey();
    }

    private function existingDynamicFilterFor(string $header): ?FellowshipFilter
    {
        /** @var Fellowship $fellowship */
        $fellowship = $this->getRecord();

        $existing = app(FellowshipFilterService::class)->findFilterByName($fellowship, $header);

        return $existing === null || $existing->is_performance ? null : $existing;
    }

    /**
     * The column a system field should start on.
     *
     * Only the Performance Topic gets one, and only from the Fellowship's own
     * data: a column named after the Fellowship's performance filter is almost
     * certainly that classification. No column name is assumed anywhere else.
     */
    private function defaultHeaderFor(FellowshipImportMappingTarget $target): ?string
    {
        if ($target !== FellowshipImportMappingTarget::PerformanceTopic) {
            return null;
        }

        /** @var Fellowship $fellowship */
        $fellowship = $this->getRecord();

        $performanceFilterName = $fellowship->performanceFilter?->name;

        if ($performanceFilterName === null) {
            return null;
        }

        foreach ($this->headers() as $header) {
            if (mb_strtolower(trim($header)) === mb_strtolower(trim($performanceFilterName))) {
                return $header;
            }
        }

        return null;
    }

    /**
     * Header keys are positional rather than the header text itself: a column
     * named "Option 1 (2)" or with a dot in it cannot be a Livewire state path.
     */
    private function headerKey(int $index): string
    {
        return 'column_'.$index;
    }

    private function optionKey(int $slot): string
    {
        return 'option_'.$slot;
    }

    private function optionExplanationKey(int $slot): string
    {
        return 'option_explanation_'.$slot;
    }

    /**
     * Rebuild the mapping schema against the columns that are now known.
     *
     * Its components are generated from the uploaded file's headers, and
     * Filament builds each schema once per request — the first build in this
     * request happened before the upload had been read, when there were no
     * columns to build from. Dropping the cached schema is what makes the step
     * render the file's columns instead of nothing at all.
     *
     * @param  array<string, mixed>|null  $state  Null fills the defaults.
     */
    private function rebuildMappingForm(?array $state = null): void
    {
        $this->cacheSchema('mappingForm', null);

        $state === null
            ? $this->mappingForm->fill()
            : $this->mappingForm->fill($state);
    }

    /**
     * Turn the form's field-to-column answers into the header-keyed map the
     * rest of the import reads.
     *
     * The stored shape is unchanged — one entry per header, in the file's own
     * terms — so staging, confirmation and the stored maps of past imports are
     * read exactly as before. Only the direction the admin answers in is new.
     *
     * Answer options are emitted in slot order rather than file order: slot 1 is
     * the question's first option, whichever column the admin put there.
     *
     * A column claimed twice is refused here rather than resolved. The entries
     * are keyed by header, so the second claim would quietly replace the first
     * — exactly the silent overwrite this must not do.
     *
     * @param  array<string, mixed>  $state  The mapping form's validated state.
     *
     * @throws ValidationException
     */
    private function headerMapFromForm(array $state): ImportHeaderMap
    {
        $system = is_array($state['system'] ?? null) ? $state['system'] : [];
        $entries = [];

        /** @var array<string, string> $claimedBy header => the field key that claimed it */
        $claimedBy = [];

        $claim = function (
            string $key,
            FellowshipImportMappingTarget $target,
            ?int $optionSlot = null,
        ) use ($system, &$entries, &$claimedBy): bool {
            $header = $system[$key] ?? null;

            if (! is_string($header) || $header === '') {
                return false;
            }

            if (isset($claimedBy[$header])) {
                throw ValidationException::withMessages([
                    'mappingData.system.'.$key => __('admin.fellowship.import_column_claimed_twice', [
                        'column' => $header,
                        'field' => $this->fieldLabel($claimedBy[$header]),
                    ]),
                ]);
            }

            $claimedBy[$header] = $key;

            $entries[$header] = [
                'target' => $target->value,
                'filter_id' => null,
                'filter_name' => null,
                'option_slot' => $optionSlot,
            ];

            return true;
        };

        foreach (FellowshipImportMappingTarget::systemFields() as $target) {
            $claim($target->value, $target);
        }

        // An explanation is stored against the option's position among the
        // columns that were actually mapped, not against its slot number: slot
        // 4 with slot 3 left empty is the third option the question will have,
        // and the explanation has to land on that one.
        $explanationSlots = [];
        $mappedOptionPosition = 0;

        for ($slot = 1; $slot <= $this->answerOptionSlotCount(); $slot++) {
            if ($claim($this->optionKey($slot), FellowshipImportMappingTarget::AnswerOption)) {
                $explanationSlots[$slot] = ++$mappedOptionPosition;
            }
        }

        for ($slot = 1; $slot <= $this->answerOptionSlotCount(); $slot++) {
            // An explanation for an option column the admin did not map has no
            // answer to explain, so it is left out rather than attached to
            // whichever option happens to sit in that position.
            if (! isset($explanationSlots[$slot])) {
                continue;
            }

            $claim(
                $this->optionExplanationKey($slot),
                FellowshipImportMappingTarget::AnswerOptionExplanation,
                $explanationSlots[$slot],
            );
        }

        foreach ($this->headers() as $index => $header) {
            // Claimed by a system field: its left-over row is hidden and has
            // nothing to say about this column.
            if (isset($claimedBy[$header])) {
                continue;
            }

            $row = $state[$this->headerKey($index)] ?? null;

            if (! is_array($row)) {
                continue;
            }

            $target = FellowshipImportMappingTarget::tryFrom((string) ($row['target'] ?? ''))
                ?? FellowshipImportMappingTarget::Ignore;

            if ($target === FellowshipImportMappingTarget::Ignore) {
                continue;
            }

            $entries[$header] = [
                'target' => $target->value,
                'filter_id' => $row['filter_id'] ?? null,
                'filter_name' => $row['filter_name'] ?? null,
            ];
        }

        return ImportHeaderMap::fromArray($entries);
    }

    /**
     * One mapping field's label, for a message that has to name it.
     */
    private function fieldLabel(string $key): string
    {
        $target = FellowshipImportMappingTarget::tryFrom($key);

        if ($target !== null) {
            return $this->targetLabel($target);
        }

        // Checked before the plain option prefix, which this key also starts
        // with.
        if (str_starts_with($key, 'option_explanation_')) {
            return __('admin.fellowship.field_import_answer_option_explanation_number', [
                'number' => (int) str_replace('option_explanation_', '', $key),
            ]);
        }

        return __('admin.fellowship.field_import_answer_option_number', [
            'number' => (int) str_replace('option_', '', $key),
        ]);
    }

    /**
     * Rebuild the mapping form's state from the stored map, so going back to
     * mapping shows what the admin chose rather than the defaults.
     *
     * The stored map is header-keyed; the form is field-keyed. This is the one
     * place that reads the map backwards, which is why answer options are taken
     * in the order they were stored — the order they will be imported in.
     *
     * @return array<string, mixed>
     */
    private function mappingStateFromImport(): array
    {
        $map = $this->headerMap();
        $stored = $map->toArray();

        $system = [];

        foreach (FellowshipImportMappingTarget::systemFields() as $target) {
            $system[$target->value] = $map->headerFor($target);
        }

        $optionHeaders = $map->headersFor(FellowshipImportMappingTarget::AnswerOption);
        // Keyed by the option's stored position, which is the slot each option
        // is restored into above.
        $explanationHeaders = $map->optionExplanationHeaders();

        for ($slot = 1; $slot <= $this->answerOptionSlotCount(); $slot++) {
            $system[$this->optionKey($slot)] = $optionHeaders[$slot - 1] ?? null;
            $system[$this->optionExplanationKey($slot)] = $explanationHeaders[$slot] ?? null;
        }

        $state = ['system' => $system];

        foreach ($this->headers() as $index => $header) {
            $entry = $stored[$header] ?? null;
            $target = FellowshipImportMappingTarget::tryFrom((string) ($entry['target'] ?? ''));

            // Only a dynamic filter belongs in a left-over row; a header the
            // map gave a system field is shown by that field's own dropdown.
            $isFilterEntry = $target !== null && $target->isDynamicFilter();

            $state[$this->headerKey($index)] = [
                'target' => $isFilterEntry ? $target->value : $this->defaultTargetFor($header)->value,
                'filter_id' => $isFilterEntry ? ($entry['filter_id'] ?? null) : $this->defaultFilterIdFor($header),
                'filter_name' => $isFilterEntry ? ($entry['filter_name'] ?? null) : $header,
            ];
        }

        return $state;
    }

    /**
     * Load the decisions already recorded, leaving every other duplicate blank
     * so it reads as awaiting one.
     */
    private function loadDecisions(FellowshipImport $import): void
    {
        $this->decisions = $import->duplicateRows()
            ->get()
            ->mapWithKeys(fn (FellowshipImportRow $row): array => [
                $row->getKey() => $row->decision?->value ?? '',
            ])
            ->all();

        $this->selected = [];
        $this->selectAll = false;
    }

    private function rowFor(int $rowId): ?FellowshipImportRow
    {
        $import = $this->currentImport();

        if ($import === null) {
            return null;
        }

        /** @var FellowshipImportRow|null $row */
        $row = $import->rows()->whereKey($rowId)->first();

        return $row;
    }

    /**
     * Filament's FileUpload state is keyed by a generated id; the stored path is
     * its value.
     */
    /**
     * The stored path behind the upload field, from the dehydrated form state.
     *
     * The state is a map keyed by Filament's generated file ids. A value that
     * is not a stored path — a TemporaryUploadedFile that was never saved, say
     * — is no path at all and is reported as a missing upload.
     *
     * The value is checked, not merely read. Form state is Livewire state and
     * so the client's to set, and after dehydration the field holds nothing more
     * than a string; a crafted request could put any path on the import disk
     * there. {@see ImportFilePath} is what distinguishes a path the upload step
     * produced from one the browser simply asked for, so an admin who may import
     * questions cannot use the wizard to read a file that is not theirs.
     */
    private function storedUploadPath(mixed $file): ?string
    {
        foreach (is_array($file) ? $file : [$file] as $value) {
            $path = ImportFilePath::sanitize($value);

            if ($path !== null) {
                return $path;
            }
        }

        return null;
    }

    /**
     * The client filename of the upload still pending on the field, if any.
     */
    private function pendingUploadFilename(): ?string
    {
        $file = $this->uploadData['file'] ?? null;

        foreach (is_array($file) ? $file : [$file] as $value) {
            if ($value instanceof TemporaryUploadedFile) {
                return $value->getClientOriginalName();
            }
        }

        return null;
    }
}
