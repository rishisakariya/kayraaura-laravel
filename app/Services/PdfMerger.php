<?php

namespace App\Services;

use DomainException;
use setasign\Fpdi\Fpdi;
use setasign\Fpdi\PdfParser\StreamReader;

class PdfMerger
{
    // A4 portrait page size in millimetres, used when packing several labels per page.
    private const A4_WIDTH_MM = 210.0;

    private const A4_HEIGHT_MM = 297.0;

    private const GRID_MARGIN_MM = 4.0;

    private const GRID_GUTTER_MM = 2.0;

    /**
     * Merge PDF files into a single PDF.
     *
     * @param  list<string>  $filePaths
     * @param  int  $perPage  How many source pages to place on each output page (1 = one per page).
     */
    public function merge(array $filePaths, int $perPage = 1): string
    {
        if ($filePaths === []) {
            throw new DomainException('No PDF files were provided for merging');
        }

        $sources = [];

        foreach ($filePaths as $filePath) {
            if (!is_file($filePath)) {
                throw new DomainException("PDF file not found: {$filePath}");
            }

            $sources[] = $filePath;
        }

        return $this->render($sources, $perPage);
    }

    /**
     * Crop a single-page PDF down to the bounding box of its actual content.
     *
     * Some carrier labels (e.g. Delhivery's A4 desktop format) place the real
     * label artwork in just one corner of an otherwise blank page. Packing that
     * mostly-empty page into a grid makes the label look tiny. This trims the
     * page to the artwork so it fills its grid cell. If the content box cannot be
     * detected, the original PDF is returned unchanged.
     */
    public function cropToContent(string $pdfBinary): string
    {
        if ($pdfBinary === '' || !str_starts_with($pdfBinary, '%PDF')) {
            return $pdfBinary;
        }

        $box = $this->detectContentBoxPt($pdfBinary);

        if ($box === null) {
            return $pdfBinary;
        }

        [$cx0, $cy0, $cx1, $cy1, $mediaHeight] = $box;
        $cropWidth = $cx1 - $cx0;
        $cropHeight = $cy1 - $cy0;

        try {
            $pdf = new Fpdi('P', 'pt', [$cropWidth, $cropHeight]);
            $pdf->setSourceFile(StreamReader::createByString($pdfBinary));
            $template = $pdf->importPage(1);
            $size = $pdf->getTemplateSize($template);

            $pdf->AddPage('P', [$cropWidth, $cropHeight]);

            // Shift the full page so the detected content box aligns to the page
            // origin; everything outside the new page is clipped when this page is
            // later imported as a form XObject during merging.
            $offsetX = -$cx0;
            $offsetY = -($mediaHeight - $cy1);

            $pdf->useTemplate($template, $offsetX, $offsetY, $size['width'], $size['height']);

            return $pdf->Output('S');
        } catch (\Throwable $e) {
            return $pdfBinary;
        }
    }

    /**
     * Merge multiple in-memory PDF binaries into a single PDF.
     *
     * @param  list<string>  $pdfBinaries
     * @param  int  $perPage  How many source pages to place on each output page (1 = one per page).
     */
    public function mergeBinaries(array $pdfBinaries, int $perPage = 1): string
    {
        if ($pdfBinaries === []) {
            throw new DomainException('No PDF binaries were provided for merging');
        }

        $sources = [];

        foreach ($pdfBinaries as $binary) {
            if ($binary === '' || !str_starts_with($binary, '%PDF')) {
                throw new DomainException('Invalid PDF binary provided for merging');
            }

            $sources[] = StreamReader::createByString($binary);
        }

        return $this->render($sources, $perPage);
    }

    /**
     * @param  list<string|StreamReader>  $sources
     */
    private function render(array $sources, int $perPage): string
    {
        $perPage = max(1, $perPage);

        return $perPage === 1
            ? $this->renderOnePerPage($sources)
            : $this->renderGrid($sources, $perPage);
    }

    /**
     * @param  list<string|StreamReader>  $sources
     */
    private function renderOnePerPage(array $sources): string
    {
        $pdf = new Fpdi();

        foreach ($sources as $source) {
            $pageCount = $pdf->setSourceFile($source);

            for ($pageNumber = 1; $pageNumber <= $pageCount; $pageNumber++) {
                $template = $pdf->importPage($pageNumber);
                $size = $pdf->getTemplateSize($template);

                $pdf->AddPage($size['orientation'], [$size['width'], $size['height']]);
                $pdf->useTemplate($template);
            }
        }

        return $pdf->Output('S');
    }

    /**
     * Pack several source pages onto each A4 page in a centered grid.
     *
     * @param  list<string|StreamReader>  $sources
     */
    private function renderGrid(array $sources, int $perPage): string
    {
        [$columns, $rows] = $this->gridDimensions($perPage);

        $usableWidth = self::A4_WIDTH_MM - (2 * self::GRID_MARGIN_MM);
        $usableHeight = self::A4_HEIGHT_MM - (2 * self::GRID_MARGIN_MM);

        $cellWidth = ($usableWidth - (($columns - 1) * self::GRID_GUTTER_MM)) / $columns;
        $cellHeight = ($usableHeight - (($rows - 1) * self::GRID_GUTTER_MM)) / $rows;

        $pdf = new Fpdi();
        $slot = 0;

        foreach ($sources as $source) {
            $pageCount = $pdf->setSourceFile($source);

            for ($pageNumber = 1; $pageNumber <= $pageCount; $pageNumber++) {
                $positionOnPage = $slot % $perPage;

                if ($positionOnPage === 0) {
                    $pdf->AddPage('P', [self::A4_WIDTH_MM, self::A4_HEIGHT_MM]);
                }

                $column = $positionOnPage % $columns;
                $row = intdiv($positionOnPage, $columns);

                $template = $pdf->importPage($pageNumber);
                $size = $pdf->getTemplateSize($template);

                // Scale the label to fit its cell while preserving aspect ratio.
                $scale = min($cellWidth / $size['width'], $cellHeight / $size['height']);
                $drawWidth = $size['width'] * $scale;
                $drawHeight = $size['height'] * $scale;

                $cellX = self::GRID_MARGIN_MM + ($column * ($cellWidth + self::GRID_GUTTER_MM));
                $cellY = self::GRID_MARGIN_MM + ($row * ($cellHeight + self::GRID_GUTTER_MM));

                // Center the label within its cell.
                $x = $cellX + (($cellWidth - $drawWidth) / 2);
                $y = $cellY + (($cellHeight - $drawHeight) / 2);

                $pdf->useTemplate($template, $x, $y, $drawWidth, $drawHeight);

                $slot++;
            }
        }

        return $pdf->Output('S');
    }

    /**
     * Work out a sensible columns x rows grid for the requested labels per page.
     *
     * @return array{0: int, 1: int}
     */
    private function gridDimensions(int $perPage): array
    {
        return match ($perPage) {
            2 => [1, 2],
            3 => [1, 3],
            4 => [2, 2],
            6 => [2, 3],
            8 => [2, 4],
            9 => [3, 3],
            default => $this->squareishGrid($perPage),
        };
    }

    /**
     * @return array{0: int, 1: int}
     */
    private function squareishGrid(int $perPage): array
    {
        $columns = (int) ceil(sqrt($perPage));
        $rows = (int) ceil($perPage / $columns);

        return [$columns, $rows];
    }

    /**
     * Detect the bounding box (in PDF points) of the artwork on a label page that
     * places a single form XObject onto an otherwise blank page.
     *
     * @return array{0: float, 1: float, 2: float, 3: float, 4: float}|null
     *         [contentX0, contentY0, contentX1, contentY1, mediaHeight] or null.
     */
    private function detectContentBoxPt(string $bytes): ?array
    {
        try {
            if (!preg_match('/\/MediaBox\s*\[\s*([\-\d.]+)\s+([\-\d.]+)\s+([\-\d.]+)\s+([\-\d.]+)\s*\]/', $bytes, $mb)) {
                return null;
            }

            $mediaX0 = (float) $mb[1];
            $mediaY0 = (float) $mb[2];
            $mediaX1 = (float) $mb[3];
            $mediaY1 = (float) $mb[4];
            $mediaWidth = $mediaX1 - $mediaX0;
            $mediaHeight = $mediaY1 - $mediaY0;

            if ($mediaWidth <= 0 || $mediaHeight <= 0) {
                return null;
            }

            $placement = $this->findSinglePlacement($bytes);

            if ($placement === null) {
                return null;
            }

            [$a, $b, $c, $d, $e, $f, $name] = $placement;

            $bbox = $this->findXObjectBBox($bytes, $name);

            if ($bbox === null) {
                return null;
            }

            [$bx0, $by0, $bx1, $by1] = $bbox;

            $map = static fn (float $x, float $y): array => [$a * $x + $c * $y + $e, $b * $x + $d * $y + $f];

            $corners = [$map($bx0, $by0), $map($bx1, $by0), $map($bx0, $by1), $map($bx1, $by1)];
            $xs = array_column($corners, 0);
            $ys = array_column($corners, 1);

            $cx0 = max($mediaX0, min($xs));
            $cy0 = max($mediaY0, min($ys));
            $cx1 = min($mediaX1, max($xs));
            $cy1 = min($mediaY1, max($ys));

            if (($cx1 - $cx0) < 20 || ($cy1 - $cy0) < 20) {
                return null;
            }

            // If the content already fills most of the page there is nothing to trim.
            if (($cx1 - $cx0) > 0.95 * $mediaWidth && ($cy1 - $cy0) > 0.95 * $mediaHeight) {
                return null;
            }

            return [$cx0, $cy0, $cx1, $cy1, $mediaHeight];
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Find a short page-content stream that places exactly one XObject and return
     * its transform matrix and XObject name.
     *
     * @return array{0: float, 1: float, 2: float, 3: float, 4: float, 5: float, 6: string}|null
     */
    private function findSinglePlacement(string $bytes): ?array
    {
        if (!preg_match_all('/stream\r?\n(.*?)\r?\nendstream/s', $bytes, $streams)) {
            return null;
        }

        foreach ($streams[1] as $stream) {
            $data = @gzuncompress($stream);

            if ($data === false) {
                $data = @gzinflate($stream);
            }

            if ($data === false) {
                $data = $stream;
            }

            // The page content that positions the label is tiny; the heavy streams
            // are the artwork XObjects themselves.
            if (strlen($data) > 4000 || !preg_match('/\bDo\b/', $data)) {
                continue;
            }

            if (preg_match(
                '/([\-\d.]+)\s+([\-\d.]+)\s+([\-\d.]+)\s+([\-\d.]+)\s+([\-\d.]+)\s+([\-\d.]+)\s+cm\s+\/([A-Za-z0-9._\-]+)\s+Do/s',
                $data,
                $m
            )) {
                return [
                    (float) $m[1],
                    (float) $m[2],
                    (float) $m[3],
                    (float) $m[4],
                    (float) $m[5],
                    (float) $m[6],
                    $m[7],
                ];
            }
        }

        return null;
    }

    /**
     * Resolve the /BBox of a named form XObject by following its indirect reference.
     *
     * @return array{0: float, 1: float, 2: float, 3: float}|null
     */
    private function findXObjectBBox(string $bytes, string $name): ?array
    {
        if (!preg_match('#/' . preg_quote($name, '#') . '\s+(\d+)\s+(\d+)\s+R#', $bytes, $ref)) {
            return null;
        }

        $objNumber = $ref[1];

        if (!preg_match('/\b' . $objNumber . '\s+0\s+obj\b(.*?)(?:stream|endobj)/s', $bytes, $obj)) {
            return null;
        }

        if (!preg_match('/\/BBox\s*\[\s*([\-\d.]+)\s+([\-\d.]+)\s+([\-\d.]+)\s+([\-\d.]+)\s*\]/', $obj[1], $bb)) {
            return null;
        }

        return [(float) $bb[1], (float) $bb[2], (float) $bb[3], (float) $bb[4]];
    }
}
