<?php

namespace App\Services\Imports;

use Illuminate\Http\UploadedFile;
use League\Csv\Reader;
use PhpOffice\PhpSpreadsheet\IOFactory;
use RuntimeException;

final class StudentImportFileReader
{
    public const MAX_ROWS = 2000;

    public function read(UploadedFile $file): array
    {
        $extension = strtolower($file->getClientOriginalExtension());

        if ($extension === 'csv') {
            return $this->readCsv($file);
        }

        if (in_array($extension, ['xls', 'xlsx'], true)) {
            return $this->readSpreadsheet($file);
        }

        throw new RuntimeException('Formato de archivo no soportado.');
    }

    private function readCsv(UploadedFile $file): array
    {
        $csv = Reader::from($file->getRealPath(), 'r');
        $records = iterator_to_array($csv->getRecords(), false);
        $headerRecord = array_shift($records);
        if ($headerRecord === null) {
            return ['headers' => [], 'rows' => [], 'sheet' => null];
        }

        $headers = $this->uniqueHeaders(array_map(
            static fn ($value): string => is_string($value) ? $value : (string) ($value ?? ''),
            $headerRecord,
        ));
        $rows = [];

        foreach ($records as $record) {
            $row = [];
            foreach ($headers as $index => $header) {
                $row[$header] = isset($record[$index]) && is_string($record[$index])
                    ? $record[$index]
                    : (string) ($record[$index] ?? '');
            }
            $rows[] = $row;
            $this->assertRowsLimit($rows);
        }

        return ['headers' => $headers, 'rows' => $rows, 'sheet' => null];
    }

    private function readSpreadsheet(UploadedFile $file): array
    {
        $spreadsheet = IOFactory::load($file->getRealPath(), \PhpOffice\PhpSpreadsheet\Reader\IReader::READ_DATA_ONLY);
        $worksheet = $spreadsheet->getActiveSheet();
        $values = $worksheet->toArray(null, true, true, false);
        $headers = $this->uniqueHeaders(array_map(static fn ($value) => is_string($value) ? $value : (string) ($value ?? ''), array_shift($values) ?? []));
        $rows = [];

        foreach ($values as $valuesRow) {
            $row = [];
            foreach ($headers as $index => $header) {
                $row[$header] = $valuesRow[$index] ?? null;
            }
            $rows[] = $row;
            $this->assertRowsLimit($rows);
        }

        return [
            'headers' => $headers,
            'rows' => $rows,
            'sheet' => $worksheet->getTitle(),
        ];
    }

    /**
     * Spreadsheet/CSV headers are not required to be unique, but the import
     * mapping needs stable keys so repeated financial columns are not lost.
     * The first occurrence keeps its label; following occurrences are made
     * explicit for manual mapping (e.g. TOTAL, TOTAL [2], TOTAL [3]).
     */
    private function uniqueHeaders(array $headers): array
    {
        $seen = [];

        return array_map(static function (string $header) use (&$seen): string {
            $base = trim($header);
            if ($base === '') {
                $base = 'COLUMN';
            }

            $seen[$base] = ($seen[$base] ?? 0) + 1;
            return $seen[$base] === 1 ? $base : sprintf('%s [%d]', $base, $seen[$base]);
        }, $headers);
    }

    private function assertRowsLimit(array $rows): void
    {
        if (count($rows) > self::MAX_ROWS) {
            throw new RuntimeException('El archivo supera el máximo de 2000 filas.');
        }
    }
}
