<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('kpi_configurations', function (Blueprint $table) {
            $table->dropForeign(['kpi_definition_id']);
            $table->dropIndex(['kpi_definition_id', 'effective_from']);
            $table->unique(['kpi_definition_id', 'effective_from'], 'kpi_config_effective_from_unique');
            $table->foreign('kpi_definition_id')->references('id')->on('kpi_definitions')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('kpi_configurations', function (Blueprint $table) {
            $table->dropForeign(['kpi_definition_id']);
            $table->dropUnique('kpi_config_effective_from_unique');
            $table->index(['kpi_definition_id', 'effective_from']);
            $table->foreign('kpi_definition_id')->references('id')->on('kpi_definitions')->cascadeOnDelete();
        });
    }
};
