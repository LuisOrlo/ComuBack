<?php

namespace Tests\Unit;

use App\Services\Imports\StudentImportFileReader;
use Illuminate\Http\UploadedFile;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xls;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class StudentImportFileReaderTest extends TestCase
{
    public function test_csv_preserves_repeated_headers_with_stable_suffixes(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'student-import-csv-');
        file_put_contents($path, "NOMBRES,TOTAL,ABONO,SALDO,TOTAL,ABONO,SALDO\nAna,72,72,0,57,30,27\n");

        try {
            $result = $this->read($path, 'duplicated.csv');
            $this->assertSame(['NOMBRES', 'TOTAL', 'ABONO', 'SALDO', 'TOTAL [2]', 'ABONO [2]', 'SALDO [2]'], $result['headers']);
            $this->assertSame('72', $result['rows'][0]['TOTAL']);
            $this->assertSame('57', $result['rows'][0]['TOTAL [2]']);
        } finally {
            @unlink($path);
        }
    }

    #[DataProvider('spreadsheetProvider')]
    public function test_spreadsheets_preserve_repeated_headers(string $extension): void
    {
        $path = tempnam(sys_get_temp_dir(), "student-import-{$extension}-");
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        foreach ([['NOMBRES', 'TOTAL', 'ABONO', 'SALDO', 'TOTAL', 'ABONO', 'SALDO'], ['Ana', 72, 72, 0, 57, 30, 27]] as $rowIndex => $row) {
            foreach ($row as $columnIndex => $value) {
                $sheet->setCellValueByColumnAndRow($columnIndex + 1, $rowIndex + 1, $value);
            }
        }

        try {
            $writer = $extension === 'xls' ? new Xls($spreadsheet) : new Xlsx($spreadsheet);
            $writer->save($path);
            $result = $this->read($path, "duplicated.{$extension}");
            $this->assertSame('72', $result['rows'][0]['TOTAL']);
            $this->assertSame('57', $result['rows'][0]['TOTAL [2]']);
        } finally {
            @unlink($path);
        }
    }

    public static function spreadsheetProvider(): array
    {
        return [['xls'], ['xlsx']];
    }

    private function read(string $path, string $name): array
    {
        return (new StudentImportFileReader())->read(new UploadedFile($path, $name, null, UPLOAD_ERR_OK, true));
    }
}
