<?php

namespace App\Services;

use DomainException;
use setasign\Fpdi\Fpdi;

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

            $pageCount = $pdf->setSourceFile($filePath);

            for ($pageNumber = 1; $pageNumber <= $pageCount; $pageNumber++) {
                $template = $pdf->importPage($pageNumber);
                $size = $pdf->getTemplateSize($template);

                $pdf->AddPage($size['orientation'], [$size['width'], $size['height']]);
                $pdf->useTemplate($template);
            }
        }

        return $pdf->Output('S');
    }
}
