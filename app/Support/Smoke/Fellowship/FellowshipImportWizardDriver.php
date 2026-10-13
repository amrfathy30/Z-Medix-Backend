<?php

namespace App\Support\Smoke\Fellowship;

use App\Enums\FellowshipImportMappingTarget;
use App\Filament\Resources\FellowshipResource\Pages\ImportFellowshipQuestions;
use App\Models\Admin;
use App\Models\Fellowship;
use Filament\Facades\Filament;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

/**
 * Drives the real import wizard — the Livewire page an admin uses, not a
 * re-implementation of it.
 *
 * Everything goes through the page's own public surface: the upload field
 * receives a genuine upload through Livewire's upload lifecycle, the mapping
 * dropdowns are set by their own state paths, and staging, the duplicate
 * decisions and confirmation are the page's own methods. Nothing here writes a
 * question, a filter or an import row directly, because a driver that did would
 * be testing itself.
 *
 * The upload deserves a word. Assigning an already-stored path to the field
 * would skip the part most likely to break — Livewire's temporary upload, its
 * validation, and Filament moving the file onto the import disk — so the driver
 * calls Livewire's own `upload()`, which performs exactly the calls the browser's
 * JavaScript makes. The field therefore holds a real `TemporaryUploadedFile`,
 * and the stored path is the one the page itself produced.
 */
class FellowshipImportWizardDriver
{
    /**
     * Act as this admin for everything that follows, through the admin guard and
     * the Filament panel the pages belong to.
     */
    public function actingAs(Admin $admin): void
    {
        Auth::guard('admin')->login($admin);
        Filament::setCurrentPanel('admin');
    }

    public function forget(): void
    {
        Auth::guard('admin')->logout();
    }

    /**
     * Open the wizard on one Fellowship. The record in the route is the only
     * thing that decides where the import lands.
     */
    public function open(Fellowship $fellowship): Testable
    {
        return Livewire::test(ImportFellowshipQuestions::class, ['record' => $fellowship->getKey()]);
    }

    /**
     * Resume an import already in progress, as the admin does by returning to
     * the review URL.
     */
    public function reopen(Fellowship $fellowship, int $importId): Testable
    {
        return Livewire::test(ImportFellowshipQuestions::class, [
            'record' => $fellowship->getKey(),
            'import' => $importId,
        ]);
    }

    /**
     * Upload a workbook through the field's real lifecycle.
     *
     * The file is read off disk and handed to Livewire's upload simulation,
     * which validates it and parks it in the temporary upload directory exactly
     * as a browser upload would; `submitUpload` then moves it onto the
     * configured import disk.
     */
    public function upload(Testable $component, string $absolutePath, ?string $filename = null): Testable
    {
        $upload = UploadedFile::fake()->createWithContent(
            $filename ?? basename($absolutePath),
            (string) file_get_contents($absolutePath),
        );

        return $component->upload('uploadData.file', [$upload]);
    }

    /**
     * Upload, then read the header row — steps one and two of the wizard.
     */
    public function uploadAndRead(Testable $component, string $absolutePath, ?string $filename = null): Testable
    {
        return $this->upload($component, $absolutePath, $filename)->call('submitUpload');
    }

    /**
     * Fill the mapping step: which column feeds each system field, and what
     * becomes of the columns left over.
     *
     * The order is forced by the page's own design. The dynamic-filter section
     * is hidden until every required system field has a column, so the system
     * fields are set first and the leftover columns only afterwards — which is
     * also the order an admin fills it in.
     *
     * @param  array<string, string>  $systemFields  Field key (e.g. 'question_text', 'option_1') => header.
     * @param  array<string, array{target: string, filter_id?: int|null, filter_name?: string|null}>  $extraColumns  Header => what to do with it.
     */
    public function map(Testable $component, array $systemFields, array $extraColumns = []): Testable
    {
        foreach ($systemFields as $key => $header) {
            $component->set('mappingData.system.'.$key, $header);
        }

        if ($extraColumns === []) {
            return $component;
        }

        $headers = $this->headers($component);

        foreach ($extraColumns as $header => $entry) {
            $index = array_search($header, $headers, true);

            if ($index === false) {
                continue;
            }

            $key = 'mappingData.column_'.$index;

            $component->set($key.'.target', $entry['target']);

            if (($entry['target'] ?? null) === FellowshipImportMappingTarget::ExistingFilter->value) {
                $component->set($key.'.filter_id', $entry['filter_id'] ?? null);
            }

            if (($entry['target'] ?? null) === FellowshipImportMappingTarget::NewFilter->value) {
                $component->set($key.'.filter_name', $entry['filter_name'] ?? $header);
            }
        }

        return $component;
    }

    /**
     * Stage the mapped file: the step that validates and duplicate-matches every
     * row without writing a question.
     */
    public function stage(Testable $component): Testable
    {
        return $component->call('submitMapping');
    }

    /**
     * Record one duplicate row's decision the way the review table's select
     * does, so the page's own `updatedDecisions` hook runs.
     */
    public function decide(Testable $component, int $rowId, string $decision): Testable
    {
        return $component->set('decisions.'.$rowId, $decision);
    }

    /**
     * Tick rows for a bulk action.
     *
     * @param  list<int>  $rowIds
     */
    public function select(Testable $component, array $rowIds): Testable
    {
        foreach ($rowIds as $rowId) {
            $component->set('selected.'.$rowId, true);
        }

        return $component;
    }

    public function selectAll(Testable $component): Testable
    {
        return $component->set('selectAll', true);
    }

    public function clearSelection(Testable $component): Testable
    {
        return $component->call('clearSelection');
    }

    public function applyBulk(Testable $component, string $decision): Testable
    {
        return $component->call('applyBulkDecision', $decision);
    }

    public function confirm(Testable $component): Testable
    {
        return $component->call('confirm');
    }

    /**
     * The headers the page read out of the uploaded workbook.
     *
     * @return list<string>
     */
    public function headers(Testable $component): array
    {
        /** @var list<string> $headers */
        $headers = $component->instance()->headers();

        return $headers;
    }

    /**
     * The first value the page found in each column, as the mapping step shows
     * it beneath each dropdown.
     *
     * @return array<string, string>
     */
    public function sampleValues(Testable $component): array
    {
        /** @var array<string, string> $samples */
        $samples = $component->instance()->sampleValues();

        return $samples;
    }

    /**
     * Build a system-field mapping from a header list by position, for a file
     * whose columns are known.
     *
     * @param  array<string, string>  $assignments  Field key => header.
     * @return array<string, string>
     */
    public function systemFields(array $assignments): array
    {
        return $assignments;
    }

    /**
     * The option-slot keys for a given number of answer columns.
     *
     * @param  list<string>  $headers
     * @return array<string, string>
     */
    public function optionSlots(array $headers): array
    {
        $slots = [];

        foreach ($headers as $index => $header) {
            $slots['option_'.($index + 1)] = $header;
        }

        return $slots;
    }
}
