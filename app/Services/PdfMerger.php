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

    private const GRID_MARGIN_MM = 1.0;

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
}
