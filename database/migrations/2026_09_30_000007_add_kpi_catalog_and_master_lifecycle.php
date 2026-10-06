<?php

use App\Models\KpiDefinition;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('kpi_definitions', function (Blueprint $table) {
            $table->enum('calculation_mode', ['system', 'manual'])->default('manual')->after('data_source');
            $table->foreignId('created_by')->nullable()->after('is_active')->constrained('users')->nullOnDelete();
            $table->timestamp('archived_at')->nullable()->after('created_by');
            $table->foreignId('archived_by')->nullable()->after('archived_at')->constrained('users')->nullOnDelete();
            $table->text('archive_reason')->nullable()->after('archived_by');
        });

        DB::table('kpi_definitions')
            ->whereIn('code', KpiDefinition::SYSTEM_DERIVED_CODES)
            ->update(['calculation_mode' => 'system']);

        Schema::create('kpi_configurations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('kpi_definition_id')->constrained()->cascadeOnDelete();
            $table->decimal('target', 15, 2);
            $table->decimal('warning_threshold', 15, 2)->nullable();
            $table->decimal('weight', 5, 2);
            $table->string('owner_name', 160);
            $table->date('effective_from');
            $table->date('effective_until')->nullable();
            $table->boolean('is_active')->default(true);
            $table->text('change_reason');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['kpi_definition_id', 'effective_from']);
            $table->index(['effective_from', 'effective_until']);
        });

        $definitions = DB::table('kpi_definitions')->orderBy('id')->get();
        foreach ($definitions as $definition) {
            DB::table('kpi_configurations')->insert([
                'kpi_definition_id' => $definition->id,
                'target' => $definition->target,
                'warning_threshold' => $definition->warning_threshold,
                'weight' => $definition->weight,
                'owner_name' => 'Pemilik KPI '.$definition->code,
                'effective_from' => '2026-01-01',
                'effective_until' => null,
                'is_active' => (bool) $definition->is_active,
                'change_reason' => 'Baseline konfigurasi hasil migrasi Iterasi 8.',
                'created_by' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        foreach (['certification_schemes', 'tuks', 'assessors', 'it_services'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->timestamp('archived_at')->nullable();
                $table->foreignId('archived_by')->nullable()->constrained('users')->nullOnDelete();
                $table->text('archive_reason')->nullable();
            });
        }
    }

    public function down(): void
    {
        foreach (['certification_schemes', 'tuks', 'assessors', 'it_services'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->dropConstrainedForeignId('archived_by');
                $table->dropColumn(['archived_at', 'archive_reason']);
            });
        }

        Schema::dropIfExists('kpi_configurations');

        Schema::table('kpi_definitions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('created_by');
            $table->dropConstrainedForeignId('archived_by');
            $table->dropColumn(['calculation_mode', 'archived_at', 'archive_reason']);
        });
    }
};
