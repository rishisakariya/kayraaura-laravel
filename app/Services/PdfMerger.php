<?php

namespace App\Services;

use DomainException;
use setasign\Fpdi\Fpdi;
use setasign\Fpdi\PdfParser\StreamReader;

class PdfMerger
{
    /**
     * @param  list<string>  $filePaths
     */
    public function merge(array $filePaths): string
    {
        if ($filePaths === []) {
            throw new DomainException('No PDF files were provided for merging');
        }

        $pdf = new Fpdi();

        foreach ($filePaths as $filePath) {
            if (!is_file($filePath)) {
                throw new DomainException("PDF file not found: {$filePath}");
            }

            $this->appendSource($pdf, $filePath);
        }

        return $pdf->Output('S');
    }

    /**
     * Merge multiple in-memory PDF binaries into a single PDF.
     *
     * @param  list<string>  $pdfBinaries
     */
    public function mergeBinaries(array $pdfBinaries): string
    {
        if ($pdfBinaries === []) {
            throw new DomainException('No PDF binaries were provided for merging');
        }

        $pdf = new Fpdi();

        foreach ($pdfBinaries as $binary) {
            if ($binary === '' || !str_starts_with($binary, '%PDF')) {
                throw new DomainException('Invalid PDF binary provided for merging');
            }

            $this->appendSource($pdf, StreamReader::createByString($binary));
        }

        return $pdf->Output('S');
    }

    private function appendSource(Fpdi $pdf, string|StreamReader $source): void
    {
        $pageCount = $pdf->setSourceFile($source);

        for ($pageNumber = 1; $pageNumber <= $pageCount; $pageNumber++) {
            $template = $pdf->importPage($pageNumber);
            $size = $pdf->getTemplateSize($template);

            $pdf->AddPage($size['orientation'], [$size['width'], $size['height']]);
            $pdf->useTemplate($template);
        }
    }
}
