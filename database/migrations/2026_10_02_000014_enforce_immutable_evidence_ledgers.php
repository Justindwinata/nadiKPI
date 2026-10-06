<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Enforce the production MySQL immutability contract below Eloquent.
     *
     * SQLite remains supported for lightweight local/demo work, where model-level
     * guards still reject mutations. The production release gate runs on MySQL 8.x
     * and therefore verifies these database triggers during migrate:fresh.
     */
    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            return;
        }

        foreach ($this->tables() as $table) {
            DB::unprepared(sprintf(
                "CREATE TRIGGER %s_immutable_update BEFORE UPDATE ON %s FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = '%s is immutable'",
                $table,
                $table,
                $table,
            ));
            DB::unprepared(sprintf(
                "CREATE TRIGGER %s_immutable_delete BEFORE DELETE ON %s FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = '%s is immutable'",
                $table,
                $table,
                $table,
            ));
        }
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            return;
        }

        foreach ($this->tables() as $table) {
            DB::unprepared("DROP TRIGGER IF EXISTS {$table}_immutable_update");
            DB::unprepared("DROP TRIGGER IF EXISTS {$table}_immutable_delete");
        }
    }

    /** @return list<string> */
    private function tables(): array
    {
        return ['financial_records', 'certificate_issuances', 'report_snapshots'];
    }
};
