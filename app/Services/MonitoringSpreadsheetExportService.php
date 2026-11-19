<?php

namespace App\Services;

use Illuminate\Support\Str;
use RuntimeException;

class MonitoringSpreadsheetExportService
{
    public function createXlsx(array $report): string
    {
        if (! class_exists(\PhpOffice\PhpSpreadsheet\Spreadsheet::class)) {
            throw new RuntimeException(
                'PhpSpreadsheet is not installed. Run: composer require phpoffice/phpspreadsheet:^5.9'
            );
        }

        $directory = storage_path('app/private/monitoring-exports');
        if (! is_dir($directory) && ! mkdir($directory, 0775, true) && ! is_dir($directory)) {
            throw new RuntimeException('Unable to prepare monitoring export directory.');
        }

        $path = $directory.DIRECTORY_SEPARATOR.Str::uuid().'.xlsx';

        try {
            $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
            $sheet = $spreadsheet->getActiveSheet();
            $sheet->setTitle('Monitoring Export');
            $sheet->setShowGridlines(false);

            $sections = $report['sections'] ?? [];
            $maxColumns = max(2, ...array_map(
                fn ($section) => max(1, count($section['headers'] ?? [])),
                $sections ?: [[]]
            ));
            $lastColumn = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($maxColumns);

            $row = 1;
            $title = (string) ($report['title'] ?? 'Monitoring Report');
            $sheet->setCellValue('A'.$row, $title);
            $sheet->mergeCells('A'.$row.':'.$lastColumn.$row);
            $sheet->getRowDimension($row)->setRowHeight(27);
            $sheet->getStyle('A'.$row.':'.$lastColumn.$row)->applyFromArray($this->titleStyle());
            $row++;

            if (! empty($report['subtitle'])) {
                $sheet->setCellValue('A'.$row, (string) $report['subtitle']);
                $sheet->mergeCells('A'.$row.':'.$lastColumn.$row);
                $sheet->getRowDimension($row)->setRowHeight(21);
                $sheet->getStyle('A'.$row.':'.$lastColumn.$row)->applyFromArray($this->subtitleStyle());
                $row++;
            }

            $sheet->setCellValue('A'.$row, 'Generated: '.now()->format('Y-m-d H:i'));
            $sheet->mergeCells('A'.$row.':'.$lastColumn.$row);
            $sheet->getStyle('A'.$row.':'.$lastColumn.$row)->applyFromArray($this->metaStyle());
            $row += 2;

            foreach ($sections as $section) {
                $sectionTitle = (string) ($section['title'] ?? '');
                $sheet->setCellValue('A'.$row, $sectionTitle);
                $sheet->mergeCells('A'.$row.':'.$lastColumn.$row);
                $sheet->getRowDimension($row)->setRowHeight(22);
                $sheet->getStyle('A'.$row.':'.$lastColumn.$row)->applyFromArray($this->sectionStyle());
                $row++;

                $headers = array_values($section['headers'] ?? []);
                if ($headers !== []) {
                    foreach ($headers as $index => $header) {
                        $column = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($index + 1);
                        $sheet->setCellValue($column.$row, (string) $header);
                    }
                    $headerLastColumn = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex(count($headers));
                    $sheet->getRowDimension($row)->setRowHeight(30);
                    $sheet->getStyle('A'.$row.':'.$headerLastColumn.$row)->applyFromArray($this->headerStyle());
                    $row++;
                }

                $types = $section['types'] ?? [];
                foreach ($section['rows'] ?? [] as $rawRow) {
                    $values = array_values($rawRow);
                    foreach ($values as $index => $value) {
                        $type = $types[$index] ?? 'text';
                        $column = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($index + 1);
                        $coordinate = $column.$row;
                        $this->writeCell($sheet, $coordinate, $value, $type);
                    }

                    if ($values !== []) {
                        $dataLastColumn = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex(count($values));
                        $sheet->getStyle('A'.$row.':'.$dataLastColumn.$row)->applyFromArray($this->bodyStyle());

                        foreach ($values as $index => $value) {
                            $type = $types[$index] ?? 'text';
                            $column = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($index + 1);
                            $coordinate = $column.$row;
                            $this->applyCellFormat($sheet, $coordinate, $type, $index === 0);
                        }
                    }

                    $sheet->getRowDimension($row)->setRowHeight(21);
                    $row++;
                }

                $row++;
            }

            $lastDataRow = max(1, $row - 1);
            $widths = $this->columnWidths($report, $maxColumns);
            foreach ($widths as $index => $width) {
                $column = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($index + 1);
                $sheet->getColumnDimension($column)->setWidth($width);
            }

            $sheet->freezePane('A6');
            // Page/print settings belong to PhpSpreadsheet's PageSetup object.
            // Calling setPrintArea() directly on Worksheet causes a RuntimeException
            // with current PhpSpreadsheet releases.
            $pageSetup = $sheet->getPageSetup();
            $pageSetup
                ->setOrientation(\PhpOffice\PhpSpreadsheet\Worksheet\PageSetup::ORIENTATION_LANDSCAPE)
                ->setPaperSize(\PhpOffice\PhpSpreadsheet\Worksheet\PageSetup::PAPERSIZE_A4)
                ->setFitToPage(true)
                ->setFitToWidth(1)
                ->setFitToHeight(0)
                ->setPrintArea('A1:'.$lastColumn.$lastDataRow);

            $sheet->getPageMargins()
                ->setLeft(0.25)
                ->setRight(0.25)
                ->setTop(0.45)
                ->setBottom(0.45)
                ->setHeader(0.2)
                ->setFooter(0.2);

            $spreadsheet->getProperties()
                ->setCreator('DOST Central Visayas PATH')
                ->setLastModifiedBy('DOST Central Visayas PATH')
                ->setTitle($title)
                ->setSubject('PATH Monitoring Export');

            $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
            $writer->setPreCalculateFormulas(false);
            $writer->save($path);
            $spreadsheet->disconnectWorksheets();
            unset($spreadsheet);
        } catch (\Throwable $e) {
            @unlink($path);
            throw new RuntimeException('Unable to generate monitoring Excel export: '.$e->getMessage(), 0, $e);
        }

        return $path;
    }

    private function writeCell(object $sheet, string $coordinate, mixed $value, string $type): void
    {
        if ($value === null || $value === '') {
            $sheet->setCellValue($coordinate, '');
            return;
        }

        if ($value instanceof \DateTimeInterface) {
            $sheet->setCellValue($coordinate, \PhpOffice\PhpSpreadsheet\Shared\Date::PHPToExcel($value));
            return;
        }

        if (in_array($type, ['integer', 'number', 'money', 'percent', 'percent_red', 'year'], true) && is_numeric($value)) {
            $sheet->setCellValue($coordinate, (float) $value);
            return;
        }

        $sheet->setCellValueExplicit(
            $coordinate,
            $this->scalarValue($value),
            \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING
        );
    }

    private function applyCellFormat(object $sheet, string $coordinate, string $type, bool $firstColumn): void
    {
        $style = $sheet->getStyle($coordinate);
        $style->getAlignment()->setHorizontal(
            $firstColumn || $type === 'text'
                ? \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_LEFT
                : \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_RIGHT
        );

        switch ($type) {
            case 'integer':
                $style->getNumberFormat()->setFormatCode('#,##0');
                break;
            case 'year':
                $style->getNumberFormat()->setFormatCode('0');
                $style->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);
                break;
            case 'number':
                $style->getNumberFormat()->setFormatCode('#,##0.00');
                break;
            case 'money':
                $style->getNumberFormat()->setFormatCode('"PHP "#,##0.00');
                break;
            case 'percent':
            case 'percent_red':
                // Report values are already expressed as 0-100 percentages.
                $style->getNumberFormat()->setFormatCode('0.00"%"');
                if ($type === 'percent_red') {
                    $style->getFont()->setColor(new \PhpOffice\PhpSpreadsheet\Style\Color('FFC62828'));
                    $style->getFont()->setBold(true);
                }
                break;
            case 'date':
                $style->getNumberFormat()->setFormatCode('yyyy-mm-dd');
                break;
        }

        if ($firstColumn) {
            $style->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID);
            $style->getFill()->getStartColor()->setARGB('FFEAF2F5');
            $style->getFont()->setBold(true);
            $style->getFont()->getColor()->setARGB('FF123E56');
        }
    }

    private function titleStyle(): array
    {
        return [
            'font' => ['bold' => true, 'size' => 16, 'color' => ['argb' => 'FFFFFFFF']],
            'fill' => ['fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID, 'startColor' => ['argb' => 'FF123E56']],
            'alignment' => ['horizontal' => \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER, 'vertical' => \PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER],
        ];
    }

    private function subtitleStyle(): array
    {
        return [
            'font' => ['bold' => true, 'size' => 10, 'color' => ['argb' => 'FF123E56']],
            'fill' => ['fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID, 'startColor' => ['argb' => 'FFEAF2F5']],
            'alignment' => ['vertical' => \PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER],
        ];
    }

    private function metaStyle(): array
    {
        return [
            'font' => ['italic' => true, 'size' => 9, 'color' => ['argb' => 'FF667085']],
        ];
    }

    private function sectionStyle(): array
    {
        return [
            'font' => ['bold' => true, 'size' => 11, 'color' => ['argb' => 'FFFFFFFF']],
            'fill' => ['fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID, 'startColor' => ['argb' => 'FF2E6B55']],
            'alignment' => ['vertical' => \PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER],
        ];
    }

    private function headerStyle(): array
    {
        return [
            'font' => ['bold' => true, 'size' => 10, 'color' => ['argb' => 'FFFFFFFF']],
            'fill' => ['fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID, 'startColor' => ['argb' => 'FF087F86']],
            'alignment' => [
                'horizontal' => \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER,
                'vertical' => \PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER,
                'wrapText' => true,
            ],
            'borders' => $this->borderArray(),
        ];
    }

    private function bodyStyle(): array
    {
        return [
            'font' => ['size' => 10, 'color' => ['argb' => 'FF1F2937']],
            'alignment' => [
                'vertical' => \PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_TOP,
                'wrapText' => true,
            ],
            'borders' => $this->borderArray(),
        ];
    }

    private function borderArray(): array
    {
        return [
            'allBorders' => [
                'borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN,
                'color' => ['argb' => 'FFD5DEE3'],
            ],
        ];
    }

    private function columnWidths(array $report, int $maxColumns): array
    {
        $widths = array_fill(0, $maxColumns, 12.0);
        foreach ($report['sections'] ?? [] as $section) {
            $headers = $section['headers'] ?? [];
            $rows = array_slice($section['rows'] ?? [], 0, 60);

            for ($column = 0; $column < $maxColumns; $column++) {
                $maxLength = isset($headers[$column]) ? $this->textLength((string) $headers[$column]) : 0;
                foreach ($rows as $row) {
                    if (array_key_exists($column, $row)) {
                        $type = $section['types'][$column] ?? 'text';
                        $maxLength = max($maxLength, $this->textLength($this->displayValue($row[$column], $type)));
                    }
                }
                $widths[$column] = max($widths[$column], min(36, max(10, $maxLength + 2)));
            }
        }

        return $widths;
    }

    private function displayValue(mixed $value, string $type): string
    {
        if ($value === null || $value === '') {
            return '';
        }
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d');
        }

        return match ($type) {
            'integer' => number_format((float) $value, 0, '.', ','),
            'number' => number_format((float) $value, 2, '.', ','),
            'money' => 'PHP '.number_format((float) $value, 2, '.', ','),
            'percent', 'percent_red' => number_format((float) $value, 2, '.', ',').'%',
            'year' => (string) ((int) $value),
            default => (string) $this->scalarValue($value),
        };
    }

    private function scalarValue(mixed $value): string
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d');
        }
        if (is_bool($value)) {
            return $value ? 'Yes' : 'No';
        }
        return (string) ($value ?? '');
    }

    private function textLength(string $value): int
    {
        return function_exists('mb_strlen') ? mb_strlen($value) : strlen($value);
    }
}
