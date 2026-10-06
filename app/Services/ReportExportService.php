<?php

namespace App\Services;

use App\Models\ReportSnapshot;
use Illuminate\Support\Arr;

class ReportExportService
{
    private const TABLE_KEYS = [
        'kpis', 'departments', 'batches', 'invoices', 'records', 'services', 'incidents', 'data_quality_runs',
        'findings', 'corrective_actions', 'appeals', 'obligations', 'signals', 'actions', 'management_reviews', 'items',
    ];

    public function json(ReportSnapshot $snapshot): string
    {
        return json_encode($this->document($snapshot), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n";
    }

    public function csv(ReportSnapshot $snapshot): string
    {
        $payload = $snapshot->payload ?? [];
        $rows = [
            ['NADI REPORT SNAPSHOT'],
            ['Reference', $snapshot->reference],
            ['Title', $snapshot->title],
            ['Period', $snapshot->period_start?->toDateString().' — '.$snapshot->period_end?->toDateString()],
            ['Generated at', $snapshot->generated_at?->toIso8601String()],
            ['SHA-256', $snapshot->content_hash],
            [],
        ];

        $summary = $this->summaryRows($payload);
        if ($summary) {
            $rows[] = ['SUMMARY'];
            $rows[] = ['Section', 'Metric', 'Value'];
            foreach ($summary as $row) $rows[] = $row;
            $rows[] = [];
        }

        $kpis = $payload['kpi_configuration_snapshot'] ?? [];
        if ($kpis) {
            $rows[] = ['KPI SNAPSHOT'];
            $rows[] = ['Code', 'Name', 'Actual', 'Target', 'Warning', 'Weight', 'Status', 'Owner', 'Source'];
            foreach ($kpis as $row) {
                $rows[] = [
                    $row['code'] ?? '', $row['name'] ?? '', $row['actual'] ?? '', $row['target'] ?? '',
                    $row['warning_threshold'] ?? '', $row['weight'] ?? '', $row['status'] ?? '', $row['owner_name'] ?? '', $row['data_source'] ?? '',
                ];
            }
        }

        return $this->rowsToCsv($rows);
    }

    public function html(ReportSnapshot $snapshot): string
    {
        $payload = $snapshot->payload ?? [];
        $summary = $this->summaryRows($payload);
        $kpis = $payload['kpi_configuration_snapshot'] ?? [];
        $tables = $this->extractTables($payload);
        $e = fn ($value) => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        $html = '<!doctype html><html lang="id"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">';
        $html .= '<title>'.$e($snapshot->title).'</title><style>'.self::PRINT_CSS.'</style></head><body>';
        $html .= '<header><div class="brand">NADI · LSP MIGAS</div><h1>'.$e($snapshot->title).'</h1><p>'.$e($snapshot->reference).' · '.$e($snapshot->period_start?->format('d M Y')).' — '.$e($snapshot->period_end?->format('d M Y')).'</p></header>';
        $html .= '<section class="meta"><div><b>Generated</b><span>'.$e($snapshot->generated_at?->format('d M Y H:i T')).'</span></div><div><b>Snapshot SHA-256</b><span class="hash">'.$e($snapshot->content_hash).'</span></div></section>';

        if ($summary) {
            $html .= '<h2>Ringkasan</h2><div class="cards">';
            foreach ($summary as [$section, $metric, $value]) {
                $html .= '<div class="card"><small>'.$e($section).'</small><b>'.$e($this->humanize($metric)).'</b><span>'.$e($this->scalar($value)).'</span></div>';
            }
            $html .= '</div>';
        }

        if ($kpis) {
            $html .= '<h2>KPI Snapshot</h2>'.$this->htmlTable($kpis, ['code','name','actual','target','warning_threshold','weight','status','owner_name'], $e);
        }

        foreach ($tables as $name => $rows) {
            if ($name === 'kpis' || ! $rows) continue;
            $html .= '<h2>'.$e($this->humanize($name)).'</h2>'.$this->htmlTable(array_slice($rows, 0, 100), null, $e);
        }

        $manifest = $snapshot->source_manifest ?? [];
        if (! empty($manifest['import_batches'])) {
            $html .= '<h2>Provenance / Import Batches</h2>'.$this->htmlTable($manifest['import_batches'], ['reference','dataset_name','file_name','sha256','accepted_rows','rejected_rows','reconciliation_status'], $e);
        }
        $html .= '<footer>Snapshot immutable · Content hash: '.$e($snapshot->content_hash).'</footer></body></html>';

        return $html;
    }

    public function evidencePack(ReportSnapshot $snapshot): string
    {
        $zip = new SimpleZipWriter($snapshot->generated_at);
        $document = $this->document($snapshot);

        $files = [
            'report.json' => json_encode($document, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n",
            'report.csv' => $this->csv($snapshot),
            'printable-report.html' => $this->html($snapshot),
            'kpi_snapshot.csv' => $this->tableCsv($snapshot->payload['kpi_configuration_snapshot'] ?? []),
            'provenance.csv' => $this->tableCsv($snapshot->source_manifest['import_batches'] ?? []),
        ];

        foreach ($this->extractTables($snapshot->payload ?? []) as $name => $rows) {
            if (! $rows) continue;
            $files['tables/'.$this->safeName($name).'.csv'] = $this->tableCsv($rows);
        }
        ksort($files, SORT_STRING);

        $fileManifest = [];
        foreach ($files as $name => $contents) {
            $fileManifest[] = [
                'name' => $name,
                'bytes' => strlen($contents),
                'sha256' => hash('sha256', $contents),
            ];
        }

        $manifest = json_encode([
            'reference' => $snapshot->reference,
            'title' => $snapshot->title,
            'report_type' => $snapshot->report_type,
            'period_start' => $snapshot->period_start?->toDateString(),
            'period_end' => $snapshot->period_end?->toDateString(),
            'generated_at' => $snapshot->generated_at?->toIso8601String(),
            'content_hash_sha256' => $snapshot->content_hash,
            'schema_version' => $snapshot->schema_version,
            'files' => $fileManifest,
            'verification' => 'SHA-256 setiap file dihitung dari byte entry ZIP; content_hash_sha256 mengikat payload + source_manifest snapshot.',
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n";

        $zip->add('manifest.json', $manifest);
        foreach ($files as $name => $contents) {
            $zip->add($name, $contents);
        }

        return $zip->finish();
    }

    private function document(ReportSnapshot $snapshot): array
    {
        return [
            'snapshot' => [
                'reference' => $snapshot->reference,
                'title' => $snapshot->title,
                'report_type' => $snapshot->report_type,
                'period_start' => $snapshot->period_start?->toDateString(),
                'period_end' => $snapshot->period_end?->toDateString(),
                'generated_at' => $snapshot->generated_at?->toIso8601String(),
                'content_hash_sha256' => $snapshot->content_hash,
                'schema_version' => $snapshot->schema_version,
            ],
            'payload' => $snapshot->payload,
            'source_manifest' => $snapshot->source_manifest,
        ];
    }

    private function summaryRows(array $payload): array
    {
        $rows = [];
        $walk = function ($value, string $path = '') use (&$walk, &$rows): void {
            if (! is_array($value)) return;
            foreach ($value as $key => $child) {
                $childPath = trim($path.'.'.$key, '.');
                if ($key === 'summary' && is_array($child)) {
                    foreach ($child as $metric => $metricValue) {
                        if (is_scalar($metricValue) || $metricValue === null) $rows[] = [$path ?: 'report', $metric, $metricValue];
                    }
                    continue;
                }
                if (in_array($key, ['overview'], true) && is_array($child)) {
                    foreach (['overall_score','overall_status','open_actions','overdue_actions'] as $metric) {
                        if (array_key_exists($metric, $child)) $rows[] = [$childPath, $metric, $child[$metric]];
                    }
                }
                $walk($child, $childPath);
            }
        };
        $walk($payload['content'] ?? []);
        return $rows;
    }

    private function extractTables(array $payload): array
    {
        $tables = [];
        $walk = function ($value) use (&$walk, &$tables): void {
            if (! is_array($value)) return;
            foreach ($value as $key => $child) {
                if (in_array($key, self::TABLE_KEYS, true) && is_array($child) && array_is_list($child)) {
                    $normalized = array_values(array_filter(array_map(fn ($row) => is_array($row) ? $row : null, $child)));
                    if ($normalized) $tables[$key] = array_merge($tables[$key] ?? [], $normalized);
                }
                $walk($child);
            }
        };
        $walk($payload);
        return $tables;
    }

    private function tableCsv(array $rows): string
    {
        if (! $rows) return "No data\n";
        $flat = array_map(fn ($row) => $this->flattenRow($row), $rows);
        $headers = [];
        foreach ($flat as $row) $headers = array_values(array_unique(array_merge($headers, array_keys($row))));
        $csvRows = [$headers];
        foreach ($flat as $row) $csvRows[] = array_map(fn ($header) => $row[$header] ?? '', $headers);
        return $this->rowsToCsv($csvRows);
    }

    private function flattenRow(array $row): array
    {
        $out = [];
        foreach ($row as $key => $value) {
            if (is_scalar($value) || $value === null) $out[$key] = $value;
            else $out[$key] = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
        return $out;
    }

    private function rowsToCsv(array $rows): string
    {
        $handle = fopen('php://temp', 'r+');
        fwrite($handle, "\xEF\xBB\xBF");
        foreach ($rows as $row) fputcsv($handle, array_map(fn ($value) => $this->csvCell($value), $row));
        rewind($handle);
        $csv = stream_get_contents($handle);
        fclose($handle);
        return $csv;
    }

    private function htmlTable(array $rows, ?array $preferred, callable $e): string
    {
        if (! $rows) return '<p class="muted">Tidak ada data pada snapshot ini.</p>';
        $flat = array_map(fn ($row) => $this->flattenRow($row), $rows);
        $headers = $preferred ?: array_slice(array_keys($flat[0]), 0, 9);
        $headers = array_values(array_filter($headers, fn ($header) => array_key_exists($header, $flat[0])));
        if (! $headers) return '<p class="muted">Tidak ada kolom yang dapat ditampilkan.</p>';
        $html = '<table><thead><tr>'.implode('', array_map(fn ($h) => '<th>'.$e($this->humanize($h)).'</th>', $headers)).'</tr></thead><tbody>';
        foreach ($flat as $row) {
            $html .= '<tr>'.implode('', array_map(fn ($h) => '<td>'.$e($this->scalar($row[$h] ?? '')).'</td>', $headers)).'</tr>';
        }
        return $html.'</tbody></table>';
    }

    private function csvCell(mixed $value): string
    {
        $scalar = $this->scalar($value);
        return is_string($value) && preg_match('/^[=+\-@]/', $scalar) ? "'".$scalar : $scalar;
    }

    private function scalar(mixed $value): string
    {
        if (is_bool($value)) return $value ? 'Ya' : 'Tidak';
        if ($value === null) return '';
        if (is_float($value)) return rtrim(rtrim(number_format($value, 3, '.', ''), '0'), '.');
        return (string) $value;
    }

    private function humanize(string $value): string
    {
        return ucwords(str_replace(['_', '.'], ' ', $value));
    }

    private function safeName(string $value): string
    {
        return trim(preg_replace('/[^a-z0-9_-]+/i', '-', strtolower($value)), '-');
    }

    private const PRINT_CSS = <<<'CSS'
@page{size:A4;margin:14mm}*{box-sizing:border-box}body{font:12px/1.45 Arial,sans-serif;color:#142321;margin:0}header{border-bottom:3px solid #0f766e;padding-bottom:12px;margin-bottom:16px}.brand{font-size:11px;font-weight:800;letter-spacing:.14em;color:#0f766e}h1{font-size:24px;margin:5px 0 3px}h2{font-size:16px;margin:22px 0 8px;color:#153c38}p{margin:4px 0}.meta{display:grid;grid-template-columns:1fr 2fr;gap:8px;margin:12px 0}.meta div,.card{border:1px solid #dfe8e6;border-radius:8px;padding:9px}.meta b,.meta span,.card small,.card b,.card span{display:block}.hash{font:10px monospace;word-break:break-all}.cards{display:grid;grid-template-columns:repeat(3,1fr);gap:8px}.card small{color:#667b78;text-transform:uppercase;font-size:9px}.card b{font-size:11px;margin:2px 0}.card span{font-size:18px;font-weight:700;color:#0f766e}table{width:100%;border-collapse:collapse;margin:5px 0 12px;font-size:9px}th,td{border:1px solid #dfe8e6;padding:5px;text-align:left;vertical-align:top;word-break:break-word}th{background:#eef6f4;color:#244b46}.muted{color:#6c7e7b}footer{margin-top:24px;border-top:1px solid #dfe8e6;padding-top:8px;color:#6c7e7b;font-size:9px;word-break:break-all}@media print{.cards{grid-template-columns:repeat(3,1fr)}h2{break-after:avoid}table{break-inside:auto}tr{break-inside:avoid}}
CSS;
}
