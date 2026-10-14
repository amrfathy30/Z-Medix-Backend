<?php

namespace App\Services\Fellowship;

use App\Contracts\Fellowship\SpreadsheetReader;
use App\Exceptions\Fellowship\FellowshipImportException;
use Maatwebsite\Excel\Facades\Excel;
use Throwable;

/**
 * Reads xlsx, xls and csv uploads through the Excel package already used by the
 * application.
 *
 * Headers are taken from the first row and used verbatim as keys, so nothing
 * here assumes what a column is called — naming is entirely the admin's to map.
 * Duplicate header names are disambiguated with a numeric suffix rather than
 * silently collapsed, since two identically-named columns are usually two
 * answer options.
 */
class ExcelSpreadsheetReader implements SpreadsheetReader
{
    /**
     * Parsed sheets, keyed by path, so a staging run that asks for the headers
     * and then the rows opens the file once.
     *
     * @var array<string, array{headers: list<string>, rows: list<array<string, string|null>>}>
     */
    private array $cache = [];

    /**
     * @return list<string>
     */
    public function headers(string $path): array
    {
        return $this->read($path)['headers'];
    }

    /**
     * @return list<array<string, string|null>>
     */
    public function rows(string $path): array
    {
        return $this->read($path)['rows'];
    }

    /**
     * @return array{headers: list<string>, rows: list<array<string, string|null>>}
     */
    private function read(string $path): array
    {
        if (isset($this->cache[$path])) {
            return $this->cache[$path];
        }

        try {
            $sheets = Excel::toArray(new RawSheetImport, $path);
        } catch (Throwable $exception) {
            throw FellowshipImportException::unreadableFile($exception->getMessage());
        }

        /** @var list<list<mixed>> $sheet */
        $sheet = $sheets[0] ?? [];

        $headerRow = null;

        // Leading blank lines above the header are common in hand-made files;
        // the first row with any content is the header.
        foreach ($sheet as $index => $row) {
            if ($this->rowHasContent($row)) {
                $headerRow = $index;

                break;
            }
        }

        if ($headerRow === null) {
            throw FellowshipImportException::emptyFile();
        }

        $headers = $this->normalizeHeaders($sheet[$headerRow]);

        if ($headers === []) {
            throw FellowshipImportException::emptyFile();
        }

        $rows = [];

        foreach (array_slice($sheet, $headerRow + 1) as $offset => $row) {
            if (! $this->rowHasContent($row)) {
                continue;
            }

            $keyed = [];

            foreach ($headers as $column => $header) {
                $keyed[$header] = $this->cell($row[$column] ?? null);
            }

            // The row number the admin sees in their spreadsheet, so a reported
            // problem can be found in the file rather than in our offsets.
            $keyed['__row_number'] = (string) ($headerRow + $offset + 2);

            $rows[] = $keyed;
        }

        return $this->cache[$path] = ['headers' => $headers, 'rows' => $rows];
    }

    /**
     * @param  list<mixed>  $row
     * @return list<string>
     */
    private function normalizeHeaders(array $row): array
    {
        $headers = [];
        $seen = [];

        foreach ($row as $value) {
            $header = trim((string) ($value ?? ''));

            if ($header === '') {
                // A blank header cannot be mapped, but dropping it would shift
                // every later column; a placeholder keeps the positions honest.
                $header = 'Column '.(count($headers) + 1);
            }

            $occurrence = $seen[$header] = ($seen[$header] ?? 0) + 1;

            $headers[] = $occurrence > 1 ? $header.' ('.$occurrence.')' : $header;
        }

        // Trailing placeholder columns are spreadsheet padding, not data.
        while ($headers !== [] && str_starts_with((string) end($headers), 'Column ')) {
            array_pop($headers);
        }

        return array_values($headers);
    }

    /**
     * @param  list<mixed>  $row
     */
    private function rowHasContent(array $row): bool
    {
        foreach ($row as $value) {
            if (trim((string) ($value ?? '')) !== '') {
                return true;
            }
        }

        return false;
    }

    private function cell(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (is_bool($value)) {
            $value = $value ? '1' : '0';
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
