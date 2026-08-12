<?php

namespace App\Reporting;

use Dompdf\Dompdf;

/**
 * functional requirements §10.5: "CSV or PDF (my choice)." Plain PHP
 * (fputcsv) for CSV and dompdf (HTML → PDF, no external service) for
 * PDF — server-rendered, downloaded on request, not pre-generated or
 * emailed (architecture doc §6.8 explicitly rules that out for this
 * phase).
 */
class ReportExporter
{
    /**
     * @param string[] $headers
     * @param array<int, array<int, string>> $rows
     */
    public function toCsv(array $headers, array $rows): string
    {
        $stream = fopen('php://temp', 'r+');
        fputcsv($stream, $headers, ',', '"', '\\');
        foreach ($rows as $row) {
            fputcsv($stream, $row, ',', '"', '\\');
        }
        rewind($stream);
        $csv = stream_get_contents($stream);
        fclose($stream);

        return $csv;
    }

    /**
     * @param string[] $headers
     * @param array<int, array<int, string>> $rows
     */
    public function toPdf(string $title, array $headers, array $rows): string
    {
        $html = '<html><head><style>'
            . 'body { font-family: sans-serif; font-size: 12px; } '
            . 'h1 { font-size: 16px; } '
            . 'table { border-collapse: collapse; width: 100%; } '
            . 'th, td { border: 1px solid #999; padding: 4px 6px; text-align: left; }'
            . '</style></head><body>';
        $html .= '<h1>' . htmlspecialchars($title) . '</h1>';
        $html .= '<table><thead><tr>';
        foreach ($headers as $header) {
            $html .= '<th>' . htmlspecialchars($header) . '</th>';
        }
        $html .= '</tr></thead><tbody>';
        foreach ($rows as $row) {
            $html .= '<tr>';
            foreach ($row as $cell) {
                $html .= '<td>' . htmlspecialchars((string) $cell) . '</td>';
            }
            $html .= '</tr>';
        }
        $html .= '</tbody></table></body></html>';

        $dompdf = new Dompdf(['isRemoteEnabled' => false]);
        $dompdf->loadHtml($html);
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();

        return $dompdf->output();
    }
}
