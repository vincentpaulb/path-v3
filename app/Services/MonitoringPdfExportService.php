<?php

namespace App\Services;

class MonitoringPdfExportService
{
    private const PAGE_WIDTH = 841.89;
    private const PAGE_HEIGHT = 595.28;
    private const MARGIN = 24.0;

    public function create(array $report): string
    {
        $pages = [];
        $content = '';
        $y = self::MARGIN;
        $this->reportHeader($content, $y, $report);

        foreach ($report['sections'] ?? [] as $section) {
            $headers = array_values($section['headers'] ?? []);
            $rows = array_values($section['rows'] ?? []);
            $columnCount = max(1, count($headers));
            $fontSize = $this->fontSizeForColumns($columnCount);
            $weights = $section['pdf_weights'] ?? array_fill(0, $columnCount, 1.0);
            $widths = $this->columnWidths($weights);

            $this->ensureSpace($pages, $content, $y, 44, $report);
            $this->sectionTitle($content, $y, (string) ($section['title'] ?? ''));

            if ($headers !== []) {
                $headerHeight = $this->rowHeight($headers, $widths, $fontSize, true);
                $this->ensureSpace($pages, $content, $y, $headerHeight + 8, $report);
                $this->tableRow($content, $y, $headers, $widths, $headerHeight, $fontSize, true);
            }

            $types = $section['types'] ?? [];
            foreach ($rows as $row) {
                $values = [];
                $cellColors = [];
                foreach (array_values($row) as $index => $value) {
                    $type = $types[$index] ?? 'text';
                    $values[] = $this->displayValue($value, $type);
                    $cellColors[] = $type === 'percent_red' ? [0.78, 0.08, 0.10] : null;
                }
                $height = $this->rowHeight($values, $widths, $fontSize, false);
                if ($y + $height > self::PAGE_HEIGHT - self::MARGIN - 18) {
                    $this->finishPage($pages, $content);
                    $content = '';
                    $y = self::MARGIN;
                    $this->continuationHeader($content, $y, $report);
                    $this->sectionTitle($content, $y, (string) ($section['title'] ?? '').' (continued)');
                    if ($headers !== []) {
                        $headerHeight = $this->rowHeight($headers, $widths, $fontSize, true);
                        $this->tableRow($content, $y, $headers, $widths, $headerHeight, $fontSize, true);
                    }
                }
                $this->tableRow($content, $y, $values, $widths, $height, $fontSize, false, $cellColors);
            }
            $y += 10;
        }

        $this->finishPage($pages, $content);

        return $this->buildPdf($pages, (string) ($report['title'] ?? 'Monitoring Report'));
    }

    private function reportHeader(string &$content, float &$y, array $report): void
    {
        $this->text($content, self::MARGIN, $y + 14, (string) ($report['title'] ?? 'Monitoring Report'), 16, true);
        $y += 24;
        if (! empty($report['subtitle'])) {
            $this->text($content, self::MARGIN, $y + 8, (string) $report['subtitle'], 8, false);
            $y += 14;
        }
        $this->text($content, self::MARGIN, $y + 8, 'Generated: '.now()->format('Y-m-d H:i'), 7, false);
        $y += 18;
        $this->line($content, self::MARGIN, $y, self::PAGE_WIDTH - self::MARGIN, $y, 0.6, [0.75, 0.80, 0.83]);
        $y += 10;
    }

    private function continuationHeader(string &$content, float &$y, array $report): void
    {
        $this->text($content, self::MARGIN, $y + 10, (string) ($report['title'] ?? 'Monitoring Report'), 10, true);
        $this->text($content, self::PAGE_WIDTH - self::MARGIN - 170, $y + 10, 'Generated '.now()->format('Y-m-d H:i'), 6.5, false);
        $y += 20;
    }

    private function sectionTitle(string &$content, float &$y, string $title): void
    {
        $this->rect($content, self::MARGIN, $y, self::PAGE_WIDTH - (self::MARGIN * 2), 20, [0.18, 0.42, 0.33]);
        $this->text($content, self::MARGIN + 7, $y + 13, $title, 9, true, [1, 1, 1]);
        $y += 24;
    }

    private function tableRow(string &$content, float &$y, array $values, array $widths, float $height, float $fontSize, bool $header, array $cellColors = []): void
    {
        $x = self::MARGIN;
        $fill = $header ? [0.03, 0.50, 0.53] : [0.98, 0.99, 0.99];
        $textColor = $header ? [1, 1, 1] : [0.08, 0.14, 0.18];

        foreach ($widths as $index => $width) {
            $this->rect($content, $x, $y, $width, $height, $fill);
            $this->strokeRect($content, $x, $y, $width, $height, [0.80, 0.84, 0.86], 0.35);
            $lines = $this->wrapText((string) ($values[$index] ?? ''), $width - 6, $fontSize, $header);
            $lineY = $y + 3 + $fontSize;
            $cellTextColor = (!$header && isset($cellColors[$index]) && is_array($cellColors[$index]))
                ? $cellColors[$index]
                : $textColor;
            foreach ($lines as $line) {
                $this->text($content, $x + 3, $lineY, $line, $fontSize, $header, $cellTextColor);
                $lineY += $fontSize + 1.4;
            }
            $x += $width;
        }
        $y += $height;
    }

    private function rowHeight(array $values, array $widths, float $fontSize, bool $bold): float
    {
        $maxLines = 1;
        foreach ($widths as $index => $width) {
            $maxLines = max($maxLines, count($this->wrapText((string) ($values[$index] ?? ''), $width - 6, $fontSize, $bold)));
        }

        return max(15, ($maxLines * ($fontSize + 1.4)) + 6);
    }

    private function wrapText(string $text, float $width, float $fontSize, bool $bold): array
    {
        $text = trim(preg_replace('/\s+/', ' ', $this->latinText($text)) ?? '');
        if ($text === '') {
            return [''];
        }

        $factor = $bold ? 0.56 : 0.52;
        $maxChars = max(3, (int) floor($width / max(1, $fontSize * $factor)));
        $words = preg_split('/\s+/', $text) ?: [$text];
        $lines = [];
        $current = '';
        foreach ($words as $word) {
            if (strlen($word) > $maxChars) {
                if ($current !== '') {
                    $lines[] = $current;
                    $current = '';
                }
                while (strlen($word) > $maxChars) {
                    $lines[] = substr($word, 0, $maxChars);
                    $word = substr($word, $maxChars);
                }
            }
            $candidate = $current === '' ? $word : $current.' '.$word;
            if (strlen($candidate) <= $maxChars) {
                $current = $candidate;
            } else {
                if ($current !== '') {
                    $lines[] = $current;
                }
                $current = $word;
            }
        }
        if ($current !== '') {
            $lines[] = $current;
        }

        return $lines ?: [''];
    }

    private function columnWidths(array $weights): array
    {
        $usable = self::PAGE_WIDTH - (self::MARGIN * 2);
        $sum = array_sum($weights) ?: count($weights);
        return array_map(fn ($weight) => $usable * ((float) $weight / $sum), $weights);
    }

    private function fontSizeForColumns(int $columns): float
    {
        return match (true) {
            $columns >= 14 => 5.2,
            $columns >= 11 => 5.7,
            $columns >= 8 => 6.2,
            $columns >= 6 => 6.8,
            default => 7.4,
        };
    }

    private function ensureSpace(array &$pages, string &$content, float &$y, float $needed, array $report): void
    {
        if ($y + $needed <= self::PAGE_HEIGHT - self::MARGIN - 18) {
            return;
        }
        $this->finishPage($pages, $content);
        $content = '';
        $y = self::MARGIN;
        $this->continuationHeader($content, $y, $report);
    }

    private function finishPage(array &$pages, string $content): void
    {
        if ($content !== '') {
            $pages[] = $content;
        }
    }

    private function text(string &$content, float $x, float $top, string $text, float $size, bool $bold = false, array $color = [0.08, 0.14, 0.18]): void
    {
        $y = self::PAGE_HEIGHT - $top;
        $font = $bold ? 'F2' : 'F1';
        $content .= sprintf("%.3F %.3F %.3F rg BT /%s %.2F Tf %.2F %.2F Td (%s) Tj ET\n",
            $color[0], $color[1], $color[2], $font, $size, $x, $y, $this->pdfEscape($this->latinText($text))
        );
    }

    private function rect(string &$content, float $x, float $top, float $width, float $height, array $color): void
    {
        $y = self::PAGE_HEIGHT - $top - $height;
        $content .= sprintf("%.3F %.3F %.3F rg %.2F %.2F %.2F %.2F re f\n", $color[0], $color[1], $color[2], $x, $y, $width, $height);
    }

    private function strokeRect(string &$content, float $x, float $top, float $width, float $height, array $color, float $lineWidth): void
    {
        $y = self::PAGE_HEIGHT - $top - $height;
        $content .= sprintf("%.3F %.3F %.3F RG %.2F w %.2F %.2F %.2F %.2F re S\n", $color[0], $color[1], $color[2], $lineWidth, $x, $y, $width, $height);
    }

    private function line(string &$content, float $x1, float $top1, float $x2, float $top2, float $width, array $color): void
    {
        $y1 = self::PAGE_HEIGHT - $top1;
        $y2 = self::PAGE_HEIGHT - $top2;
        $content .= sprintf("%.3F %.3F %.3F RG %.2F w %.2F %.2F m %.2F %.2F l S\n", $color[0], $color[1], $color[2], $width, $x1, $y1, $x2, $y2);
    }

    private function displayValue(mixed $value, string $type = 'text'): string
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d');
        }
        if (is_bool($value)) {
            return $value ? 'Yes' : 'No';
        }
        if ($value === null || $value === '') {
            return '';
        }
        if (in_array($type, ['money', 'number', 'integer', 'year', 'percent', 'percent_red'], true) && is_numeric($value)) {
            return match ($type) {
                'money' => 'PHP '.number_format((float) $value, 2, '.', ','),
                'percent', 'percent_red' => number_format((float) $value, 2, '.', ',').'%',
                'year' => (string) ((int) $value),
                'integer' => number_format((float) $value, 0, '.', ','),
                default => number_format((float) $value, 2, '.', ','),
            };
        }
        return (string) $value;
    }

    private function latinText(string $value): string
    {
        $value = str_replace(['₱', '–', '—', '→'], ['PHP ', '-', '-', '->'], $value);
        if (function_exists('iconv')) {
            $converted = @iconv('UTF-8', 'Windows-1252//TRANSLIT//IGNORE', $value);
            if ($converted !== false) {
                return $converted;
            }
        }
        return preg_replace('/[^\x20-\x7E]/', '?', $value) ?? $value;
    }

    private function pdfEscape(string $value): string
    {
        return str_replace(['\\', '(', ')', "\r", "\n"], ['\\\\', '\\(', '\\)', ' ', ' '], $value);
    }

    private function buildPdf(array $pageStreams, string $title): string
    {
        $objects = [];
        $objects[1] = '<< /Type /Catalog /Pages 2 0 R >>';
        $objects[3] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>';
        $objects[4] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>';

        $pageRefs = [];
        foreach ($pageStreams as $index => $stream) {
            $pageId = 5 + ($index * 2);
            $contentId = $pageId + 1;
            $pageRefs[] = $pageId.' 0 R';
            $objects[$pageId] = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 '.self::PAGE_WIDTH.' '.self::PAGE_HEIGHT.'] /Resources << /Font << /F1 3 0 R /F2 4 0 R >> >> /Contents '.$contentId.' 0 R >>';
            $objects[$contentId] = '<< /Length '.strlen($stream).' >>' . "\nstream\n" . $stream . "endstream";
        }
        $objects[2] = '<< /Type /Pages /Kids ['.implode(' ', $pageRefs).'] /Count '.count($pageRefs).' >>';

        ksort($objects);
        $pdf = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
        $offsets = [0 => 0];
        foreach ($objects as $id => $body) {
            $offsets[$id] = strlen($pdf);
            $pdf .= $id." 0 obj\n".$body."\nendobj\n";
        }

        $xref = strlen($pdf);
        $maxId = max(array_keys($objects));
        $pdf .= "xref\n0 ".($maxId + 1)."\n";
        $pdf .= "0000000000 65535 f \n";
        for ($id = 1; $id <= $maxId; $id++) {
            $offset = $offsets[$id] ?? 0;
            $pdf .= sprintf("%010d 00000 %s \n", $offset, isset($objects[$id]) ? 'n' : 'f');
        }
        $safeTitle = $this->pdfEscape($this->latinText($title));
        $pdf .= "trailer\n<< /Size ".($maxId + 1)." /Root 1 0 R /Info << /Title (".$safeTitle.") /Producer (PATH Monitoring Export) >> >>\n";
        $pdf .= "startxref\n".$xref."\n%%EOF";

        return $pdf;
    }
}
