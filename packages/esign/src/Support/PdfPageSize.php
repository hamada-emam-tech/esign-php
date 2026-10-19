<?php

declare(strict_types=1);

namespace HamadaEmamTech\Esign\Support;

/**
 * Reads the page height of a PDF without a PDF library, for converting field positions.
 *
 * SignatureField measures y from the TOP of the page; providers that measure from the bottom
 * (Acrobat Sign, Signit) need the page height to convert.
 * This looks at every /MediaBox in the file and answers only when they all agree: a PDF with mixed
 * page sizes, or whose page objects sit in compressed object streams, gives null, and the caller
 * falls back to the configured page_height or refuses. Guessing would put signatures in the wrong place.
 *
 * For drivers: use it to convert SignatureField positions.
 */
final class PdfPageSize
{
    /** The single page height shared by every page, in points, or null when it cannot be told safely. */
    public static function uniformHeight(string $pdf): ?float
    {
        if (! str_starts_with($pdf, '%PDF')) {
            return null;
        }
        // A rotated page swaps width and height on screen; refuse rather than guess the orientation.
        if (preg_match('#/Rotate\s+-?(90|270)\b#', $pdf) === 1) {
            return null;
        }
        $number = '(-?\d+(?:\.\d+)?|-?\.\d+)';
        if (preg_match_all("#/MediaBox\s*\[\s*{$number}\s+{$number}\s+{$number}\s+{$number}\s*\]#", $pdf, $m, PREG_SET_ORDER) < 1) {
            return null;
        }

        $heights = [];
        foreach ($m as $box) {
            $heights[(string) round(abs((float) $box[4] - (float) $box[2]), 2)] = true;
        }

        return count($heights) === 1 ? (float) array_key_first($heights) : null;
    }
}
