# Reporting & Management Evidence

NADI reporting uses immutable snapshots. A report is not a live view that silently changes after a KPI target, operational record, or source file is updated.

## Report types

- Executive KPI & Management Report — Pimpinan only.
- Department Performance — scoped to one department; non-directors are forced to their own department.
- Certification Operational Report.
- Finance Performance Report.
- IT Reliability & Data Quality Report.
- Governance & Compliance Report.
- Risk & Action Register.
- Management Review Report — snapshot of one review and its agenda/evidence.

Availability is derived from the user's existing domain permissions. `reports.view` controls access to the reporting workspace and `reports.export` controls downloadable/printable output.

## Snapshot contract

`report_snapshots` stores:

```text
reference
report_type
title
department_id (optional)
management_review_id (optional)
period_start / period_end
generated_at / generated_by
payload
source_manifest
content_hash (SHA-256)
schema_version
```

The SHA-256 is calculated over canonical JSON containing both `payload` and `source_manifest`. Therefore the checksum protects KPI values/configuration and the provenance manifest together.

The payload also stores a KPI configuration snapshot: target, warning threshold, weight, owner, actual value, status, calculation/source type, and effective configuration date used when the report was generated.

## Provenance manifest

The evidence manifest includes active data-source registry information and relevant import batches with:

```text
source code/name/authority rank
import reference
dataset
file name
SHA-256 file fingerprint
accepted/rejected rows
reconciliation status
import timestamp
```

Normal monthly reports retain the selected reporting month while the provenance manifest covers the trailing analysis window used by KPI trend/operational analytics. Management Review reports use the review period.

## Exports

A stored snapshot can be exported without recomputing live data.

### Printable HTML

The printable view is optimized for A4 and can be printed/saved to PDF by the browser. It includes snapshot metadata, checksum, summary metrics, KPI configuration snapshot, evidence tables, and provenance.

### JSON

Machine-readable complete snapshot including source manifest.

### CSV

Human-readable summary plus KPI snapshot. CSV cells beginning with spreadsheet formula characters (`=`, `+`, `-`, `@`) are escaped to reduce formula-injection risk.

### Evidence Pack ZIP

The ZIP is created by NADI's dependency-free ZIP writer and contains, depending on report content:

```text
manifest.json
report.json
report.csv
printable-report.html
kpi_snapshot.csv
provenance.csv
tables/*.csv
```

The pack can be validated with standard ZIP tools. `manifest.json` records the report reference, period, schema version, generation time, and content SHA-256.

## Audit trail

The following report operations create `audit_logs` entries:

```text
generate_report_snapshot
view_printable_report
export_report_snapshot
```

The export audit includes output format and snapshot checksum.

## Security / segregation of duties

- Director: report view/export for all authorized data; executive reporting enabled.
- Department head: report view/export subject to domain permissions; department reports are scoped to their own department.
- Analyst/viewer: report view by default; downloadable/printable export is not granted by default.
- Existing domain permissions still gate report types. A Finance user cannot generate a Certification report unless separately granted `certification.view`.
- Direct URL access to a stored snapshot is re-authorized server-side.

## Verification checklist

```bash
php artisan route:list --path=api/reports
php artisan test --filter=ReportingWorkflowTest
npm run build
```

For the generated evidence pack:

```bash
unzip -t <report-reference>.zip
```

A production release must run the PHPUnit and Vite gates in an environment with the required PHP extensions, PDO MySQL driver, and clean Node dependencies.

## Deterministic Evidence Pack integrity (Iteration 14.9)

Evidence Pack export is treated as auditable evidence, not a best-effort download. For the same immutable `ReportSnapshot`, repeated exports use the snapshot generation timestamp as ZIP metadata and must produce deterministic bytes when the payload is unchanged.

`manifest.json` records each payload entry with:

- file name;
- exact byte length;
- SHA-256 digest.

The manifest covers report JSON/CSV, printable HTML, KPI snapshot, provenance CSV, and table CSV payloads. The manifest intentionally does not self-hash. ZIP entry names reject traversal segments and duplicates.

`ReportSnapshot` is immutable at the application model layer and, on production MySQL, additionally protected by database triggers against direct UPDATE/DELETE. Financial and certificate issuance evidence ledgers use the same defense-in-depth pattern.
