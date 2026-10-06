<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            return;
        }

        foreach (['audit_logs', 'authentication_events'] as $table) {
            DB::unprepared(sprintf(
                "CREATE TRIGGER %s_append_only_update BEFORE UPDATE ON %s FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = '%s is append-only'",
                $table,
                $table,
                $table,
            ));
            DB::unprepared(sprintf(
                "CREATE TRIGGER %s_append_only_delete BEFORE DELETE ON %s FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = '%s is append-only'",
                $table,
                $table,
                $table,
            ));
        }

        DB::unprepared(<<<'SQL'
CREATE TRIGGER data_import_batches_protect_identity_update
BEFORE UPDATE ON data_import_batches
FOR EACH ROW
BEGIN
    IF NOT (NEW.reference <=> OLD.reference)
       OR NOT (NEW.data_source_id <=> OLD.data_source_id)
       OR NOT (NEW.dataset_type <=> OLD.dataset_type)
       OR NOT (NEW.dataset_name <=> OLD.dataset_name)
       OR NOT (NEW.file_name <=> OLD.file_name)
       OR NOT (NEW.sha256 <=> OLD.sha256)
       OR NOT (NEW.imported_at <=> OLD.imported_at)
       OR NOT (NEW.created_by <=> OLD.created_by) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'data import batch identity is immutable';
    END IF;
END
SQL);
        DB::unprepared("CREATE TRIGGER data_import_batches_protect_delete BEFORE DELETE ON data_import_batches FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'data import batches are provenance evidence and cannot be deleted'");

        // Migration 000015 protected issuance evidence. Expand the same parent guard
        // so a certification appeal cannot be erased by deleting its batch either.
        DB::unprepared('DROP TRIGGER IF EXISTS certification_batches_protect_issued_delete');
        DB::unprepared(<<<'SQL'
CREATE TRIGGER certification_batches_protect_evidence_delete
BEFORE DELETE ON certification_batches
FOR EACH ROW
BEGIN
    IF EXISTS (SELECT 1 FROM certificate_issuances WHERE certification_batch_id = OLD.id LIMIT 1)
       OR EXISTS (SELECT 1 FROM certification_appeals WHERE certification_batch_id = OLD.id LIMIT 1) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'certification batch with issuance/appeal evidence cannot be deleted';
    END IF;
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER compliance_findings_protect_capa_delete
BEFORE DELETE ON compliance_findings
FOR EACH ROW
BEGIN
    IF EXISTS (SELECT 1 FROM corrective_actions WHERE compliance_finding_id = OLD.id LIMIT 1) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'compliance finding with CAPA evidence cannot be deleted';
    END IF;
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER data_sources_protect_provenance_delete
BEFORE DELETE ON data_sources
FOR EACH ROW
BEGIN
    IF EXISTS (SELECT 1 FROM data_import_batches WHERE data_source_id = OLD.id LIMIT 1)
       OR EXISTS (SELECT 1 FROM integration_profiles WHERE data_source_id = OLD.id LIMIT 1)
       OR EXISTS (SELECT 1 FROM integration_record_links WHERE data_source_id = OLD.id LIMIT 1) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'data source with provenance/import evidence cannot be deleted';
    END IF;
END
SQL);
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            return;
        }

        DB::unprepared('DROP TRIGGER IF EXISTS data_import_batches_protect_delete');
        DB::unprepared('DROP TRIGGER IF EXISTS data_import_batches_protect_identity_update');
        DB::unprepared('DROP TRIGGER IF EXISTS data_sources_protect_provenance_delete');
        DB::unprepared('DROP TRIGGER IF EXISTS compliance_findings_protect_capa_delete');
        DB::unprepared('DROP TRIGGER IF EXISTS certification_batches_protect_evidence_delete');

        // Restore the previous issuance-only parent protection supplied by 000015.
        DB::unprepared(<<<'SQL'
CREATE TRIGGER certification_batches_protect_issued_delete
BEFORE DELETE ON certification_batches
FOR EACH ROW
BEGIN
    IF EXISTS (SELECT 1 FROM certificate_issuances WHERE certification_batch_id = OLD.id LIMIT 1) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'certification batch with certificate issuances cannot be deleted';
    END IF;
END
SQL);

        foreach (['audit_logs', 'authentication_events'] as $table) {
            DB::unprepared("DROP TRIGGER IF EXISTS {$table}_append_only_update");
            DB::unprepared("DROP TRIGGER IF EXISTS {$table}_append_only_delete");
        }
    }
};
