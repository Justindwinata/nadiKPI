<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $duplicate = DB::table('certificate_issuances')
            ->select('reference')
            ->whereNotNull('reference')
            ->groupBy('reference')
            ->havingRaw('COUNT(*) > 1')
            ->value('reference');

        if ($duplicate !== null) {
            throw new \RuntimeException('Duplicate certificate issuance reference must be reconciled before migration: '.$duplicate);
        }

        Schema::table('certificate_issuances', function (Blueprint $table): void {
            $table->unique('reference', 'certificate_issuances_reference_unique');
        });

        if (DB::connection()->getDriverName() === 'mysql') {
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
        }
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() === 'mysql') {
            DB::unprepared('DROP TRIGGER IF EXISTS certification_batches_protect_issued_delete');
        }

        Schema::table('certificate_issuances', function (Blueprint $table): void {
            $table->dropUnique('certificate_issuances_reference_unique');
        });
    }
};
