<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const TABLES = [
        'users' => 'User identities are retained for audit attribution. Deactivate accounts instead of deleting them.',
        'risk_signals' => 'Risk signal evidence is retained. Resolve or dismiss through the lifecycle instead of deleting it.',
        'action_items' => 'Action item evidence is retained. Complete through the lifecycle instead of deleting it.',
        'management_reviews' => 'Management review evidence is retained. Close through the lifecycle instead of deleting it.',
        'management_review_items' => 'Management review item evidence cannot be deleted.',
    ];

    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            return;
        }

        foreach (self::TABLES as $table => $message) {
            $trigger = $table.'_protect_delete';
            DB::unprepared("DROP TRIGGER IF EXISTS `{$trigger}`");
            $escaped = str_replace("'", "''", $message);
            DB::unprepared(<<<SQL
CREATE TRIGGER `{$trigger}`
BEFORE DELETE ON `{$table}`
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = '{$escaped}';
END
SQL);
        }
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            return;
        }

        foreach (array_keys(self::TABLES) as $table) {
            DB::unprepared("DROP TRIGGER IF EXISTS `{$table}_protect_delete`");
        }
    }
};
