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

        // FPDF reorders the page size to match the orientation, so pick the
        // orientation that matches the crop box to avoid the page being swapped.
        $orientation = $cropWidth > $cropHeight ? 'L' : 'P';

        try {
            $pdf = new Fpdi($orientation, 'pt', [$cropWidth, $cropHeight]);
            $pdf->setSourceFile(StreamReader::createByString($pdfBinary));
            $template = $pdf->importPage(1);
            $size = $pdf->getTemplateSize($template);

            $pdf->AddPage($orientation, [$cropWidth, $cropHeight]);

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
     * Pack several source pages onto each A4 page, choosing a layout per page that
     * maximises label size for the number of labels actually on that page (so a
     * page with 2 labels fills the sheet instead of leaving empty cells).
     *
     * @param  list<string|StreamReader>  $sources
     */
    private function renderGrid(array $sources, int $perPage): string
    {
        $perPage = max(1, $perPage);

        $pdf = new Fpdi();

        // Import every page up front so we know how many labels there are and can
        // pick the best layout for each output page.
        $labels = [];

        foreach ($sources as $source) {
            $pageCount = $pdf->setSourceFile($source);

            for ($pageNumber = 1; $pageNumber <= $pageCount; $pageNumber++) {
                $template = $pdf->importPage($pageNumber);
                $size = $pdf->getTemplateSize($template);

                $labels[] = [
                    'template' => $template,
                    'width' => $size['width'],
                    'height' => $size['height'],
                ];
            }
        }

        if ($labels === []) {
            throw new DomainException('No label pages were found for merging');
        }

        $labelAspect = $labels[0]['width'] / max(0.01, $labels[0]['height']);

        $usableWidth = self::A4_WIDTH_MM - (2 * self::GRID_MARGIN_MM);
        $usableHeight = self::A4_HEIGHT_MM - (2 * self::GRID_MARGIN_MM);

        foreach (array_chunk($labels, $perPage) as $pageLabels) {
            $count = count($pageLabels);
            [$columns, $rows] = $this->bestLayout($count, $labelAspect, $usableWidth, $usableHeight);

            $cellWidth = ($usableWidth - (($columns - 1) * self::GRID_GUTTER_MM)) / $columns;
            $cellHeight = ($usableHeight - (($rows - 1) * self::GRID_GUTTER_MM)) / $rows;

            $pdf->AddPage('P', [self::A4_WIDTH_MM, self::A4_HEIGHT_MM]);

            foreach ($pageLabels as $index => $label) {
                $column = $index % $columns;
                $row = intdiv($index, $columns);

                $scale = min($cellWidth / $label['width'], $cellHeight / $label['height']);
                $drawWidth = $label['width'] * $scale;
                $drawHeight = $label['height'] * $scale;

                $cellX = self::GRID_MARGIN_MM + ($column * ($cellWidth + self::GRID_GUTTER_MM));
                $cellY = self::GRID_MARGIN_MM + ($row * ($cellHeight + self::GRID_GUTTER_MM));

                $x = $cellX + (($cellWidth - $drawWidth) / 2);
                $y = $cellY + (($cellHeight - $drawHeight) / 2);

                $pdf->useTemplate($label['template'], $x, $y, $drawWidth, $drawHeight);
            }
        }

        return $pdf->Output('S');
    }

    /**
     * Choose the columns x rows arrangement that makes each label as large as
     * possible for the given count and label aspect ratio.
     *
     * @return array{0: int, 1: int}
     */
    private function bestLayout(int $count, float $labelAspect, float $usableWidth, float $usableHeight): array
    {
        $count = max(1, $count);
        $best = [1, $count];
        $bestArea = -1.0;

        for ($columns = 1; $columns <= $count; $columns++) {
            $rows = (int) ceil($count / $columns);

            $cellWidth = ($usableWidth - (($columns - 1) * self::GRID_GUTTER_MM)) / $columns;
            $cellHeight = ($usableHeight - (($rows - 1) * self::GRID_GUTTER_MM)) / $rows;

            if ($cellWidth <= 0 || $cellHeight <= 0) {
                continue;
            }

            // Largest label (aspect-preserving) that fits this cell.
            $scale = min($cellWidth / $labelAspect, $cellHeight);
            $area = ($labelAspect * $scale) * $scale;

            if ($area > $bestArea) {
                $bestArea = $area;
                $best = [$columns, $rows];
            }
        }

        return $best;
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

            $xobject = $this->findXObject($bytes, $name);

            if ($xobject === null) {
                return null;
            }

            [$bbox, $content] = $xobject;
            [$bx0, $by0, $bx1, $by1] = $bbox;

            // Try to trim to the actual visible ink (ignoring the blank/white parts of
            // the label) so the artwork fills its grid cell. Falls back to the full
            // XObject box if detection looks unreliable, so content is never cut.
            $ink = $this->computeInkBox($content);

            if ($ink !== null) {
                // A little padding so thin strokes / glyph edges are never clipped.
                $pad = 8.0;
                $ix0 = max($bx0, $ink[0] - $pad);
                $iy0 = max($by0, $ink[1] - $pad);
                $ix1 = min($bx1, $ink[2] + $pad);
                $iy1 = min($by1, $ink[3] + $pad);

                $boxWidth = $bx1 - $bx0;
                $boxHeight = $by1 - $by0;

                if ($boxWidth > 0 && $boxHeight > 0
                    && ($ix1 - $ix0) >= 0.25 * $boxWidth
                    && ($iy1 - $iy0) >= 0.25 * $boxHeight
                    && ($ix1 - $ix0) > 0
                    && ($iy1 - $iy0) > 0
                ) {
                    $bx0 = $ix0;
                    $by0 = $iy0;
                    $bx1 = $ix1;
                    $by1 = $iy1;
                }
            }

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
     * Resolve a named form XObject (its /BBox and decompressed content stream) by
     * following its indirect reference.
     *
     * @return array{0: array{0: float, 1: float, 2: float, 3: float}, 1: string}|null
     */
    private function findXObject(string $bytes, string $name): ?array
    {
        if (!preg_match('#/' . preg_quote($name, '#') . '\s+(\d+)\s+(\d+)\s+R#', $bytes, $ref)) {
            return null;
        }

        $objNumber = $ref[1];

        if (!preg_match(
            '/\b' . $objNumber . '\s+0\s+obj\b(.*?)stream\r?\n(.*?)\r?\nendstream/s',
            $bytes,
            $obj
        )) {
            return null;
        }

        $dict = $obj[1];

        if (!preg_match('/\/BBox\s*\[\s*([\-\d.]+)\s+([\-\d.]+)\s+([\-\d.]+)\s+([\-\d.]+)\s*\]/', $dict, $bb)) {
            return null;
        }

        $content = @gzuncompress($obj[2]);

        if ($content === false) {
            $content = @gzinflate($obj[2]);
        }

        if ($content === false) {
            $content = $obj[2];
        }

        return [
            [(float) $bb[1], (float) $bb[2], (float) $bb[3], (float) $bb[4]],
            $content,
        ];
    }

    /**
     * Interpret a content stream's path/image operators (with a CTM stack) to find
     * the bounding box of the visible ink, ignoring clip rectangles and white fills.
     *
     * @return array{0: float, 1: float, 2: float, 3: float}|null
     */
    private function computeInkBox(string $content): ?array
    {
        if ($content === '') {
            return null;
        }

        $ctm = [1.0, 0.0, 0.0, 1.0, 0.0, 0.0];
        $stack = [];
        $operands = [];
        $path = [];
        $fill = [0.0, 0.0, 0.0];
        $stroke = [0.0, 0.0, 0.0];

        $minX = INF;
        $minY = INF;
        $maxX = -INF;
        $maxY = -INF;

        $apply = static fn (array $m, float $x, float $y): array => [
            $m[0] * $x + $m[2] * $y + $m[4],
            $m[1] * $x + $m[3] * $y + $m[5],
        ];

        $commit = function () use (&$path, &$minX, &$minY, &$maxX, &$maxY): void {
            foreach ($path as $p) {
                if ($p[0] < $minX) {
                    $minX = $p[0];
                }
                if ($p[1] < $minY) {
                    $minY = $p[1];
                }
                if ($p[0] > $maxX) {
                    $maxX = $p[0];
                }
                if ($p[1] > $maxY) {
                    $maxY = $p[1];
                }
            }
        };

        $isWhite = static fn (array $c): bool => $c[0] > 0.95 && $c[1] > 0.95 && $c[2] > 0.95;

        // Replace string literals with a neutral token so parentheses don't break tokenizing.
        $clean = preg_replace('/\((?:[^()\\\\]|\\\\.)*\)/', ' 0 ', $content);
        $tokens = preg_split('/\s+/', (string) $clean);

        foreach ($tokens as $t) {
            if ($t === '') {
                continue;
            }

            if (is_numeric($t)) {
                $operands[] = (float) $t;

                continue;
            }

            switch ($t) {
                case 'q':
                    $stack[] = $ctm;
                    break;
                case 'Q':
                    if ($stack) {
                        $ctm = array_pop($stack);
                    }
                    break;
                case 'cm':
                    if (count($operands) >= 6) {
                        $m = array_slice($operands, -6);
                        $ctm = [
                            $ctm[0] * $m[0] + $ctm[2] * $m[1],
                            $ctm[1] * $m[0] + $ctm[3] * $m[1],
                            $ctm[0] * $m[2] + $ctm[2] * $m[3],
                            $ctm[1] * $m[2] + $ctm[3] * $m[3],
                            $ctm[0] * $m[4] + $ctm[2] * $m[5] + $ctm[4],
                            $ctm[1] * $m[4] + $ctm[3] * $m[5] + $ctm[5],
                        ];
                    }
                    $operands = [];
                    break;
                case 'rg':
                    if (count($operands) >= 3) {
                        $fill = array_slice($operands, -3);
                    }
                    $operands = [];
                    break;
                case 'g':
                    if (count($operands) >= 1) {
                        $v = end($operands);
                        $fill = [$v, $v, $v];
                    }
                    $operands = [];
                    break;
                case 'k':
                    if (count($operands) >= 4) {
                        $a = array_slice($operands, -4);
                        $kk = $a[3];
                        $fill = [(1 - $a[0]) * (1 - $kk), (1 - $a[1]) * (1 - $kk), (1 - $a[2]) * (1 - $kk)];
                    }
                    $operands = [];
                    break;
                case 'RG':
                    if (count($operands) >= 3) {
                        $stroke = array_slice($operands, -3);
                    }
                    $operands = [];
                    break;
                case 'G':
                    if (count($operands) >= 1) {
                        $v = end($operands);
                        $stroke = [$v, $v, $v];
                    }
                    $operands = [];
                    break;
                case 're':
                    if (count($operands) >= 4) {
                        [$x, $y, $w, $h] = array_slice($operands, -4);
                        foreach ([[$x, $y], [$x + $w, $y], [$x, $y + $h], [$x + $w, $y + $h]] as $p) {
                            $path[] = $apply($ctm, $p[0], $p[1]);
                        }
                    }
                    $operands = [];
                    break;
                case 'm':
                case 'l':
                    if (count($operands) >= 2) {
                        [$x, $y] = array_slice($operands, -2);
                        $path[] = $apply($ctm, $x, $y);
                    }
                    $operands = [];
                    break;
                case 'c':
                    if (count($operands) >= 6) {
                        $a = array_slice($operands, -6);
                        for ($j = 0; $j < 6; $j += 2) {
                            $path[] = $apply($ctm, $a[$j], $a[$j + 1]);
                        }
                    }
                    $operands = [];
                    break;
                case 'v':
                case 'y':
                    if (count($operands) >= 4) {
                        $a = array_slice($operands, -4);
                        for ($j = 0; $j < 4; $j += 2) {
                            $path[] = $apply($ctm, $a[$j], $a[$j + 1]);
                        }
                    }
                    $operands = [];
                    break;
                case 'f':
                case 'F':
                case 'f*':
                    if (!$isWhite($fill)) {
                        $commit();
                    }
                    $path = [];
                    $operands = [];
                    break;
                case 'S':
                case 's':
                    if (!$isWhite($stroke)) {
                        $commit();
                    }
                    $path = [];
                    $operands = [];
                    break;
                case 'B':
                case 'B*':
                case 'b':
                case 'b*':
                    $commit();
                    $path = [];
                    $operands = [];
                    break;
                case 'W':
                case 'W*':
                    // Clipping path: keep points for the following painting/no-op but do
                    // not treat the clip region as ink.
                    break;
                case 'n':
                    $path = [];
                    $operands = [];
                    break;
                case 'Do':
                    foreach ([[0, 0], [1, 0], [0, 1], [1, 1]] as $p) {
                        $path[] = $apply($ctm, $p[0], $p[1]);
                    }
                    $commit();
                    $path = [];
                    $operands = [];
                    break;
                case 'Tj':
                case 'TJ':
                case "'":
                case '"':
                    $commit();
                    $operands = [];
                    break;
                default:
                    $operands = [];
                    break;
            }
        }

        if (!is_finite($minX) || !is_finite($maxX) || $maxX <= $minX || $maxY <= $minY) {
            return null;
        }

        return [$minX, $minY, $maxX, $maxY];
    }
}
