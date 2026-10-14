<?php

namespace Tests\Feature\Fellowship;

use App\Contracts\Fellowship\SpreadsheetReader;
use App\Exceptions\Fellowship\FellowshipImportException;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Reading an uploaded workbook.
 *
 * The point of these is that nothing about a column is assumed: the header row
 * is taken verbatim, whatever it says, because the admin is the one who decides
 * what each column means.
 */
class FellowshipSpreadsheetReaderTest extends FellowshipTestCase
{
    private function reader(): SpreadsheetReader
    {
        return app(SpreadsheetReader::class);
    }

    /**
     * @param  list<list<string|null>>  $rows
     */
    private function xlsx(array $rows, string $filename = 'questions.xlsx'): string
    {
        $spreadsheet = new Spreadsheet;
        $spreadsheet->getActiveSheet()->fromArray($rows, null, 'A1');

        $path = Storage::disk('local')->path('fellowship-imports/'.$filename);

        @mkdir(dirname($path), 0o777, true);

        (new Xlsx($spreadsheet))->save($path);

        return $path;
    }

    private function csv(array $headers, array $rows): string
    {
        return Storage::disk('local')->path($this->uploadCsv($headers, $rows));
    }

    public function test_an_xlsx_workbook_is_read_with_its_own_column_names(): void
    {
        $path = $this->xlsx([
            ['Clinical stem', 'Choice 1', 'Choice 2', 'Right answer'],
            ['Which antiplatelet?', 'Aspirin', 'Warfarin', 'Aspirin'],
        ]);

        $this->assertSame(
            ['Clinical stem', 'Choice 1', 'Choice 2', 'Right answer'],
            $this->reader()->headers($path),
        );

        $rows = $this->reader()->rows($path);

        $this->assertCount(1, $rows);
        $this->assertSame('Which antiplatelet?', $rows[0]['Clinical stem']);
        $this->assertSame('Aspirin', $rows[0]['Right answer']);
    }

    public function test_a_csv_is_read_the_same_way(): void
    {
        $path = $this->csv(
            ['Stem', 'A', 'B'],
            [['Which antiplatelet?', 'Aspirin', 'Warfarin']],
        );

        $this->assertSame(['Stem', 'A', 'B'], $this->reader()->headers($path));
        $this->assertSame('Warfarin', $this->reader()->rows($path)[0]['B']);
    }

    public function test_every_cell_is_one_value_for_its_header(): void
    {
        // A cell containing a comma is one value, not two: there is no
        // multi-value parsing anywhere.
        $path = $this->csv(
            ['Stem', 'Area'],
            [['Which drug?', 'Cardiology, Neurology']],
        );

        $this->assertSame('Cardiology, Neurology', $this->reader()->rows($path)[0]['Area']);
    }

    public function test_an_empty_cell_is_read_as_null(): void
    {
        $path = $this->csv(['Stem', 'Why'], [['Which drug?', '']]);

        $this->assertNull($this->reader()->rows($path)[0]['Why']);
    }

    public function test_surrounding_whitespace_is_trimmed(): void
    {
        $path = $this->csv([' Stem ', 'A'], [['  Which drug?  ', ' Aspirin ']]);

        $this->assertSame(['Stem', 'A'], $this->reader()->headers($path));
        $this->assertSame('Which drug?', $this->reader()->rows($path)[0]['Stem']);
    }

    public function test_two_identically_named_columns_are_kept_apart(): void
    {
        // Two answer-option columns both called "Option" is common; collapsing
        // them would silently lose an answer.
        $path = $this->xlsx([
            ['Stem', 'Option', 'Option'],
            ['Which drug?', 'Aspirin', 'Warfarin'],
        ], 'duplicate-headers.xlsx');

        $this->assertSame(['Stem', 'Option', 'Option (2)'], $this->reader()->headers($path));

        $row = $this->reader()->rows($path)[0];

        $this->assertSame('Aspirin', $row['Option']);
        $this->assertSame('Warfarin', $row['Option (2)']);
    }

    public function test_blank_lines_above_the_header_are_skipped(): void
    {
        $path = $this->xlsx([
            [null, null, null],
            ['Stem', 'A', 'B'],
            ['Which drug?', 'Aspirin', 'Warfarin'],
        ], 'leading-blanks.xlsx');

        $this->assertSame(['Stem', 'A', 'B'], $this->reader()->headers($path));

        // The row number reported is still the one the admin sees in the file.
        $this->assertSame('3', $this->reader()->rows($path)[0]['__row_number']);
    }

    public function test_trailing_blank_lines_are_not_questions(): void
    {
        $path = $this->xlsx([
            ['Stem', 'A'],
            ['Which drug?', 'Aspirin'],
            [null, null],
            [null, null],
        ], 'trailing-blanks.xlsx');

        $this->assertCount(1, $this->reader()->rows($path));
    }

    public function test_a_workbook_with_no_header_row_is_refused(): void
    {
        $path = $this->xlsx([[null, null]], 'empty.xlsx');

        $this->expectException(FellowshipImportException::class);

        $this->reader()->headers($path);
    }

    public function test_an_unreadable_file_is_refused(): void
    {
        Storage::disk('local')->put('fellowship-imports/not-a-workbook.xlsx', 'this is not a spreadsheet');

        $this->expectException(FellowshipImportException::class);

        $this->reader()->headers(Storage::disk('local')->path('fellowship-imports/not-a-workbook.xlsx'));
    }
}
