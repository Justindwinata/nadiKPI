<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('it_services', function (Blueprint $table) {
            $table->timestamp('monitoring_started_at')->nullable()->after('target_uptime');
            $table->index('monitoring_started_at');
        });
    }

    public function down(): void
    {
        Schema::table('it_services', function (Blueprint $table) {
            $table->dropIndex(['monitoring_started_at']);
            $table->dropColumn('monitoring_started_at');
        });
    }
};
