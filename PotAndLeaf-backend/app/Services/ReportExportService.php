<?php

namespace App\Services;

use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Collection;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Shared PDF (DomPDF) + Excel-compatible CSV export for report screens. */
class ReportExportService
{
    /**
     * @param  array<int, array<string, mixed>>|Collection  $rows
     * @param  array<int, string>  $headers  column keys in order
     * @param  array<string, string>  $labels  key => display label
     */
    public function pdf(string $title, array|Collection $rows, array $headers, array $labels, array $meta = [])
    {
        $rows = collect($rows)->map(fn ($r) => (array) $r)->values()->all();

        return Pdf::loadView('pdf.report-table', [
            'title'   => $title,
            'headers' => $headers,
            'labels'  => $labels,
            'rows'    => $rows,
            'meta'    => $meta,
            'generated_at' => now()->toDateTimeString(),
        ])->setPaper('a4', 'landscape');
    }

    /**
     * @param  array<int, array<string, mixed>>|Collection  $rows
     * @param  array<int, string>  $headers
     * @param  array<string, string>  $labels
     */
    public function excelCsv(string $filename, array|Collection $rows, array $headers, array $labels): StreamedResponse
    {
        $rows = collect($rows)->map(fn ($r) => (array) $r);

        return response()->streamDownload(function () use ($rows, $headers, $labels) {
            $out = fopen('php://output', 'w');
            // UTF-8 BOM so Excel opens Indian locale characters cleanly.
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, array_map(fn ($h) => $labels[$h] ?? $h, $headers));
            foreach ($rows as $row) {
                fputcsv($out, array_map(fn ($h) => $row[$h] ?? '', $headers));
            }
            fclose($out);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }
}
