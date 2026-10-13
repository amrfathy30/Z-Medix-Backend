<?php

namespace App\Support\Smoke\Fellowship;

use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * The real .xlsx workbooks the Fellowship smoke run imports.
 *
 * Genuine files written with PhpSpreadsheet and read back through the
 * application's own reader, because the import is specified in terms of what an
 * admin actually uploads: a workbook whose columns are named whatever the author
 * called them, whose correct answers are written as letters, numbers or full
 * text, and whose rows are not all well-formed.
 *
 * Every workbook is kept on disk after the run so a reviewer can open the same
 * file the importer saw.
 *
 * Five shapes, each targeting one part of the specification:
 *
 *  - A, a clean hundred-row first import;
 *  - B, a re-import mixing duplicates of A with new rows, renamed and reordered
 *    columns, a brand-new optional filter column and blank optional cells;
 *  - C, rows that must each be rejected for a stated reason, mixed in with valid
 *    ones that must still go in;
 *  - D, the mapping edge cases — option counts, header naming, ambiguity;
 *  - E, a file too narrow to hold a question at all.
 */
class FellowshipSmokeWorkbooks
{
    /** Where the generated workbooks are written, relative to the storage disk. */
    public const DIRECTORY = 'smoke/fellowship';

    /** How many valid questions workbook A carries. */
    public const WORKBOOK_A_ROWS = 110;

    /** Exact question-text duplicates of A that workbook B repeats. */
    public const WORKBOOK_B_DUPLICATES = 22;

    /** Questions new to workbook B. */
    public const WORKBOOK_B_NEW = 24;

    /**
     * Generate every workbook, overwriting any from a previous run.
     *
     * @return array<string, array{path: string, rows: int, readable_rows: int, headers: list<string>, note: string}>
     */
    public function generateAll(string $directory): array
    {
        if (! is_dir($directory)) {
            mkdir($directory, 0o775, recursive: true);
        }

        $bankA = FellowshipSmokeQuestionBank::items(self::WORKBOOK_A_ROWS, 'A');
        $bankB = FellowshipSmokeQuestionBank::items(self::WORKBOOK_B_NEW, 'B');

        return [
            'A' => $this->workbookA($directory, $bankA),
            'B' => $this->workbookB($directory, $bankA, $bankB),
            'C' => $this->workbookC($directory, $bankA),
            'D1' => $this->workbookTwoOptions($directory),
            'D2' => $this->workbookSixOptions($directory),
            'D3' => $this->workbookReorderedRenamed($directory),
            'D4' => $this->workbookAmbiguousHeaders($directory),
            'D5' => $this->workbookInsufficientColumns($directory),
        ];
    }

    /**
     * Workbook A — the clean first import.
     *
     * Correct answers cycle through the three notations the importer accepts, so
     * one file proves all three rather than three files proving one each.
     *
     * @param  list<array<string, mixed>>  $bank
     * @return array{path: string, rows: int, readable_rows: int, headers: list<string>, note: string}
     */
    private function workbookA(string $directory, array $bank): array
    {
        $headers = [
            'Question Text', 'Option A', 'Option B', 'Option C', 'Option D',
            'Correct Answer', 'Difficulty', 'Exam', 'Explanation', 'System', 'Year', 'Source',
        ];

        $rows = [];

        foreach ($bank as $index => $item) {
            $rows[] = [
                $item['text'],
                $item['options'][0],
                $item['options'][1],
                $item['options'][2],
                $item['options'][3],
                $this->correctAnswerNotation($item, $index % 3),
                $item['difficulty'],
                $item['exam'],
                $item['explanation'],
                $item['system'],
                $item['year'],
                $item['source'],
            ];
        }

        return $this->write(
            $directory.'/A_initial_import.xlsx',
            $headers,
            $rows,
            'Clean first import: '.count($rows).' valid questions, correct answers written as letters, numbers and full text.',
        );
    }

    /**
     * Workbook B — the re-import.
     *
     * Deliberately unlike A in every way the mapping step is supposed to cope
     * with: every column renamed, the order changed, an extra optional filter
     * column the Fellowship has never seen, and blank optional cells. The
     * duplicated rows carry changed explanations, and some carry changed options
     * and a changed correct answer, so the side-by-side comparison has something
     * real to show.
     *
     * @param  list<array<string, mixed>>  $bankA
     * @param  list<array<string, mixed>>  $bankB
     * @return array{path: string, rows: int, readable_rows: int, headers: list<string>, note: string}
     */
    private function workbookB(string $directory, array $bankA, array $bankB): array
    {
        // Renamed and reordered against A, with the new optional column last.
        $headers = [
            'Stem', 'Level', 'Examination', 'Choice 1', 'Choice 2', 'Choice 3', 'Choice 4',
            'Answer', 'Rationale', 'Body System', 'Exam Year', 'Reference', 'Clinical Setting',
        ];

        $settings = ['Emergency Department', 'Ward', 'Outpatient Clinic', 'Intensive Care'];
        $rows = [];

        // Exact text duplicates of A. The first third also change their options
        // and correct answer; the rest change only the explanation.
        foreach (array_slice($bankA, 0, self::WORKBOOK_B_DUPLICATES) as $index => $item) {
            $options = $item['options'];
            $correctIndex = $item['correct'];

            if ($index % 3 === 0) {
                // Rewrite the option set and change which text is correct, so
                // confirming Update Existing has an effect the comparison can
                // show. The correct answer is a reworded form of the original
                // rather than a different management, so the item stays
                // coherent while its correct *text* genuinely changes — which
                // is what a stored correct answer being replaced looks like.
                $options = [
                    'Revised answer: '.$item['options'][$item['correct']],
                    $item['options'][$item['correct']],
                    'Revised distractor: watchful waiting with no intervention',
                    'Revised distractor: refer for routine review in six months',
                ];
                $correctIndex = 0;
            }

            $rows[] = [
                $item['text'],
                $item['difficulty'],
                $item['exam'],
                $options[0], $options[1], $options[2], $options[3],
                $this->letter($correctIndex),
                'Re-import revision: '.$item['explanation'],
                $item['system'],
                $item['year'],
                $item['source'],
                // Some optional filter cells left blank on purpose.
                $index % 4 === 0 ? '' : $settings[$index % 4],
            ];
        }

        // Questions new to B.
        foreach ($bankB as $index => $item) {
            $rows[] = [
                $item['text'],
                $item['difficulty'],
                $item['exam'],
                $item['options'][0], $item['options'][1], $item['options'][2], $item['options'][3],
                $this->correctAnswerNotation($item, $index % 3),
                $item['explanation'],
                $item['system'],
                // Blank optional dynamic-filter cells on a slice of the new rows.
                $index % 5 === 0 ? '' : $item['year'],
                $index % 7 === 0 ? '' : $item['source'],
                $settings[$index % 4],
            ];
        }

        // Duplicates inside B itself: four of B's own new rows, repeated. The
        // second occurrence has no stored question to update, so only Skip and
        // Keep Both may be offered for these.
        foreach (array_slice($bankB, 0, 4) as $index => $item) {
            $rows[] = [
                $item['text'],
                $item['difficulty'],
                $item['exam'],
                $item['options'][0], $item['options'][1], $item['options'][2], $item['options'][3],
                $this->letter($item['correct']),
                'Within-file repeat: '.$item['explanation'],
                $item['system'],
                $item['year'],
                $item['source'],
                $settings[$index % 4],
            ];
        }

        return $this->write(
            $directory.'/B_reimport_duplicates.xlsx',
            $headers,
            $rows,
            sprintf(
                'Re-import: %d exact duplicates of A, %d new questions, 4 within-file duplicates, renamed and reordered columns, new "Clinical Setting" filter, blank optional cells.',
                self::WORKBOOK_B_DUPLICATES,
                self::WORKBOOK_B_NEW,
            ),
        );
    }

    /**
     * Workbook C — rows that must be rejected, and rows that must not be.
     *
     * Each invalid row targets one stated rule. Valid new rows and valid
     * duplicates of A sit among them, because the specification is that an
     * invalid row is reported and excluded without holding up its neighbours.
     *
     * @param  list<array<string, mixed>>  $bankA
     * @return array{path: string, rows: int, readable_rows: int, headers: list<string>, note: string}
     */
    private function workbookC(string $directory, array $bankA): array
    {
        $headers = [
            'Question Text', 'Option A', 'Option B', 'Option C', 'Option D',
            'Correct Answer', 'Difficulty', 'Exam', 'Explanation', 'System', 'Year', 'Source',
        ];

        $m = FellowshipSmokeQuestionBank::MARKER.'C';
        $rows = [];

        // Each entry: [label for the report, the row].
        $invalid = [
            ['empty question text', ['', 'Aspirin', 'Clopidogrel', 'Warfarin', 'Heparin', 'A', 'Easy', 'MRCP', 'Expl', 'Cardiology', '2024', 'Faculty']],
            ['one option only', [$m.'-I02 A patient needs antiplatelet therapy. Which drug?', 'Aspirin', '', '', '', 'Aspirin', 'Easy', 'MRCP', 'Expl', 'Cardiology', '2024', 'Faculty']],
            ['no options at all', [$m.'-I03 A patient needs anticoagulation. Which drug?', '', '', '', '', 'Warfarin', 'Medium', 'MRCP', 'Expl', 'Haematology', '2024', 'Faculty']],
            ['missing correct answer', [$m.'-I04 Which investigation confirms pulmonary embolism?', 'CTPA', 'Chest radiograph', 'Spirometry', 'Echocardiogram', '', 'Medium', 'MRCP', 'Expl', 'Respiratory', '2023', 'Faculty']],
            ['invalid correct-answer value', [$m.'-I05 Which drug treats status epilepticus first?', 'Lorazepam', 'Phenytoin', 'Levetiracetam', 'Sodium valproate', 'Z', 'Hard', 'MRCP', 'Expl', 'Neurology', '2023', 'Faculty']],
            ['correct answer names an empty option', [$m.'-I06 Which electrolyte abnormality peaks T waves?', 'Hyperkalaemia', 'Hypokalaemia', '', '', 'C', 'Easy', 'MRCP', 'Expl', 'Nephrology', '2022', 'Faculty']],
            ['correct answer out of range', [$m.'-I07 Which vessel is occluded in an inferior infarct?', 'Right coronary', 'Left anterior descending', 'Circumflex', 'Left main stem', '9', 'Medium', 'MRCP', 'Expl', 'Cardiology', '2022', 'Faculty']],
            ['invalid difficulty', [$m.'-I08 Which antibiotic covers meningococcus?', 'Ceftriaxone', 'Nitrofurantoin', 'Metronidazole', 'Fluconazole', 'A', 'Impossible', 'MRCP', 'Expl', 'Infectious Diseases', '2024', 'Faculty']],
            ['missing difficulty', [$m.'-I09 Which test diagnoses coeliac disease?', 'Anti-TTG antibodies', 'Faecal calprotectin', 'Amylase', 'Ferritin', 'A', '', 'MRCP', 'Expl', 'Gastroenterology', '2024', 'Faculty']],
            ['missing performance topic', [$m.'-I10 Which hormone is deficient in Addison disease?', 'Cortisol', 'Insulin', 'Thyroxine', 'Prolactin', 'A', 'Easy', '', 'Expl', 'Endocrinology', '2023', 'Faculty']],
            ['ambiguous letter versus text', [$m.'-I11 Which option is labelled A in this stem?', 'Ibuprofen', 'A', 'Paracetamol', 'Codeine', 'A', 'Medium', 'MRCP', 'Expl', 'Musculoskeletal', '2023', 'Faculty']],
            ['two identical options', [$m.'-I12 Which drug reverses opioid toxicity?', 'Naloxone', 'Naloxone', 'Flumazenil', 'Atropine', 'Flumazenil', 'Easy', 'MRCP', 'Expl', 'Pharmacology', '2022', 'Faculty']],
            ['correct answer matches nothing', [$m.'-I13 Which imaging grades aortic stenosis?', 'Echocardiography', 'Plain radiograph', 'Ultrasound of the abdomen', 'Barium swallow', 'Cardiac MRI', 'Hard', 'MRCP', 'Expl', 'Cardiology', '2025', 'Faculty']],
            ['blank question with blank answer', ['', '', '', '', '', '', '', '', '', '', '', '']],
            ['missing topic and difficulty together', [$m.'-I15 Which score grades pneumonia severity?', 'CURB-65', 'GRACE', 'Wells', 'CHA2DS2-VASc', 'A', '', '', 'Expl', 'Respiratory', '2025', 'Faculty']],
        ];

        foreach ($invalid as [, $row]) {
            $rows[] = $row;
        }

        // Valid new rows mixed in among the invalid ones.
        $validNew = FellowshipSmokeQuestionBank::items(6, 'CV');

        foreach ($validNew as $index => $item) {
            $rows[] = [
                $item['text'],
                $item['options'][0], $item['options'][1], $item['options'][2], $item['options'][3],
                $this->correctAnswerNotation($item, $index % 3),
                $item['difficulty'],
                $item['exam'],
                $item['explanation'],
                $item['system'],
                // Partially empty optional filter cells on a valid row.
                $index % 2 === 0 ? '' : $item['year'],
                $item['source'],
            ];
        }

        // Valid duplicates of A, so invalid rows and duplicates coexist.
        foreach (array_slice($bankA, 40, 5) as $item) {
            $rows[] = [
                $item['text'],
                $item['options'][0], $item['options'][1], $item['options'][2], $item['options'][3],
                $this->letter($item['correct']),
                $item['difficulty'],
                $item['exam'],
                'Workbook C duplicate: '.$item['explanation'],
                $item['system'],
                $item['year'],
                $item['source'],
            ];
        }

        // More invalid rows after the valid ones, so exclusion is not merely a
        // property of the top of the file.
        $rows[] = [$m.'-I16 Which drug is first line for anaphylaxis?', 'Adrenaline', '', '', '', '1', 'Easy', 'MRCP', 'Expl', 'Immunology', '2025', 'Faculty'];
        $rows[] = ['', 'Adrenaline', 'Chlorphenamine', 'Hydrocortisone', 'Salbutamol', 'A', 'Easy', 'MRCP', 'Expl', 'Immunology', '2025', 'Faculty'];
        $rows[] = [$m.'-I18 Which fluid is given in haemorrhagic shock?', 'Blood products', 'Dextrose 5%', 'Mannitol', 'Albumin', 'B6', 'Medium', 'MRCP', 'Expl', 'General Surgery', '2024', 'Faculty'];
        $rows[] = [$m.'-I19 Which nerve is injured in a humeral shaft fracture?', 'Radial', 'Median', 'Ulnar', 'Axillary', 'A', 'Tricky', 'MRCS', 'Expl', 'Musculoskeletal', '2024', 'Faculty'];
        $rows[] = [$m.'-I20 Which marker indicates hepatitis B immunity?', 'Anti-HBs', 'HBsAg', 'Anti-HBc IgM', 'HBeAg', '', 'Medium', '', 'Expl', 'Infectious Diseases', '2023', 'Faculty'];

        return $this->write(
            $directory.'/C_validation_failures.xlsx',
            $headers,
            $rows,
            sprintf(
                'Validation: %d lines written (one deliberately blank, which the reader skips) — %d targeted invalid rows, %d valid new rows, 5 valid duplicates of A.',
                count($rows),
                count($invalid) + 5,
                count($validNew),
            ),
        );
    }

    /**
     * D1 — two answer options only, which is the stated minimum.
     *
     * @return array{path: string, rows: int, readable_rows: int, headers: list<string>, note: string}
     */
    private function workbookTwoOptions(string $directory): array
    {
        $headers = ['Question Text', 'True', 'False', 'Correct Answer', 'Difficulty', 'Exam', 'System'];
        $bank = FellowshipSmokeQuestionBank::items(8, 'D1');
        $rows = [];

        foreach ($bank as $index => $item) {
            $rows[] = [
                $item['text'],
                $item['options'][0],
                $item['options'][1],
                $index % 2 === 0 ? 'A' : $item['options'][1],
                $item['difficulty'],
                $item['exam'],
                $item['system'],
            ];
        }

        return $this->write($directory.'/D1_two_options.xlsx', $headers, $rows, 'Mapping: exactly two answer-option columns.');
    }

    /**
     * D2 — six answer options, more than the four A uses.
     *
     * @return array{path: string, rows: int, readable_rows: int, headers: list<string>, note: string}
     */
    private function workbookSixOptions(string $directory): array
    {
        $headers = [
            'Question Text', 'Opt 1', 'Opt 2', 'Opt 3', 'Opt 4', 'Opt 5', 'Opt 6',
            'Correct Answer', 'Difficulty', 'Exam', 'System', 'Year',
        ];

        $bank = FellowshipSmokeQuestionBank::items(10, 'D2');
        $extra = ['Refer to a tertiary centre without treatment', 'Repeat the test in twelve months'];
        $rows = [];

        foreach ($bank as $index => $item) {
            $options = [...$item['options'], ...$extra];

            $rows[] = [
                $item['text'],
                $options[0], $options[1], $options[2], $options[3], $options[4], $options[5],
                // Cycle a letter beyond D, a number beyond 4, and full text, so
                // the extra slots are actually addressed.
                match ($index % 3) {
                    0 => $this->letter($item['correct']),
                    1 => (string) ($item['correct'] + 1),
                    default => $options[$item['correct']],
                },
                $item['difficulty'],
                $item['exam'],
                $item['system'],
                $item['year'],
            ];
        }

        return $this->write($directory.'/D2_six_options.xlsx', $headers, $rows, 'Mapping: six answer-option columns.');
    }

    /**
     * D3 — nothing named or ordered the way A names or orders it, plus columns
     * that exist only to be ignored.
     *
     * @return array{path: string, rows: int, readable_rows: int, headers: list<string>, note: string}
     */
    private function workbookReorderedRenamed(string $directory): array
    {
        $headers = [
            'Internal Ref', 'Specialty Track', 'Answer Key', 'Possible Answer One', 'Vignette',
            'Possible Answer Two', 'Complexity', 'Possible Answer Three', 'Teaching Note',
            'Possible Answer Four', 'Author Initials', 'Review Status',
        ];

        $bank = FellowshipSmokeQuestionBank::items(12, 'D3');
        $rows = [];

        foreach ($bank as $index => $item) {
            $rows[] = [
                'REF-'.str_pad((string) ($index + 1), 4, '0', STR_PAD_LEFT),
                $item['exam'],
                $this->letter($item['correct']),
                $item['options'][0],
                $item['text'],
                $item['options'][1],
                $item['difficulty'],
                $item['options'][2],
                $item['explanation'],
                $item['options'][3],
                'KH',
                'Approved',
            ];
        }

        return $this->write(
            $directory.'/D3_reordered_renamed.xlsx',
            $headers,
            $rows,
            'Mapping: every column renamed, order scrambled, two columns ("Internal Ref", "Author Initials", "Review Status") present only to be ignored.',
        );
    }

    /**
     * D4 — an untidy header row: a repeated name, a blank header cell between
     * populated ones, and a name that could plausibly belong to two fields.
     *
     * @return array{path: string, rows: int, readable_rows: int, headers: list<string>, note: string}
     */
    private function workbookAmbiguousHeaders(string $directory): array
    {
        // "Option" twice, a blank header in the middle, and "Answer" which could
        // read as an option or as the correct answer.
        $headers = ['Question', 'Option', 'Option', '', 'Answer', 'Difficulty', 'Exam', 'System'];

        $bank = FellowshipSmokeQuestionBank::items(8, 'D4');
        $rows = [];

        foreach ($bank as $index => $item) {
            $rows[] = [
                $item['text'],
                $item['options'][0],
                $item['options'][1],
                // The blank-headed column carries a third option, so the
                // placeholder header has to be mappable for the row to be read.
                $item['options'][2],
                $this->letter(min($item['correct'], 2)),
                $item['difficulty'],
                $item['exam'],
                $item['system'],
            ];
        }

        return $this->write(
            $directory.'/D4_ambiguous_headers.xlsx',
            $headers,
            $rows,
            'Mapping: duplicate header name ("Option" twice), one blank header cell between populated columns, one ambiguous name ("Answer").',
        );
    }

    /**
     * D5 — too few columns to hold a question, which the wizard must refuse at
     * upload rather than carry into mapping.
     *
     * @return array{path: string, rows: int, readable_rows: int, headers: list<string>, note: string}
     */
    private function workbookInsufficientColumns(string $directory): array
    {
        $headers = ['Question Text', 'Option A', 'Option B'];

        $rows = [
            [FellowshipSmokeQuestionBank::MARKER.'D5-Q001 Which drug is first line for anaphylaxis?', 'Adrenaline', 'Chlorphenamine'],
            [FellowshipSmokeQuestionBank::MARKER.'D5-Q002 Which vitamin deficiency causes scurvy?', 'Vitamin C', 'Vitamin D'],
        ];

        return $this->write(
            $directory.'/D5_insufficient_columns.xlsx',
            $headers,
            $rows,
            'Mapping: only three columns, fewer than the six a question needs — must be refused at upload.',
        );
    }

    /**
     * One of the three notations a correct answer may be written in. All three
     * must resolve to the same option.
     *
     * @param  array<string, mixed>  $item
     */
    private function correctAnswerNotation(array $item, int $notation): string
    {
        /** @var list<string> $options */
        $options = $item['options'];
        $correct = (int) $item['correct'];

        return match ($notation) {
            0 => $this->letter($correct),
            1 => (string) ($correct + 1),
            default => $options[$correct],
        };
    }

    private function letter(int $index): string
    {
        return chr(ord('A') + $index);
    }

    /**
     * Write one workbook, as a genuine xlsx.
     *
     * Cells are written explicitly as strings so a value like "1" in the correct
     * answer column comes back as "1" rather than as a number the reader would
     * have to guess the type of.
     *
     * `readable_rows` is what the application's reader will actually see: a
     * wholly blank line is spreadsheet padding rather than a row, and the reader
     * skips it, so a workbook that deliberately contains one reports a smaller
     * readable count than the number of lines written.
     *
     * @param  list<string>  $headers
     * @param  list<list<string>>  $rows
     * @return array{path: string, rows: int, readable_rows: int, headers: list<string>, note: string}
     */
    private function write(string $path, array $headers, array $rows, string $note): array
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Questions');

        foreach ($headers as $column => $header) {
            $sheet->setCellValueExplicit(
                [$column + 1, 1],
                $header,
                DataType::TYPE_STRING,
            );
        }

        foreach ($rows as $rowIndex => $row) {
            foreach ($row as $column => $value) {
                $sheet->setCellValueExplicit(
                    [$column + 1, $rowIndex + 2],
                    (string) $value,
                    DataType::TYPE_STRING,
                );
            }
        }

        (new Xlsx($spreadsheet))->save($path);
        $spreadsheet->disconnectWorksheets();

        $blank = count(array_filter(
            $rows,
            static fn (array $row): bool => implode('', array_map('trim', $row)) === '',
        ));

        return [
            'path' => $path,
            'rows' => count($rows),
            'readable_rows' => count($rows) - $blank,
            'headers' => $headers,
            'note' => $note,
        ];
    }
}
