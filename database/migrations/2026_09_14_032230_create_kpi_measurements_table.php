<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('kpi_measurements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('kpi_definition_id')->constrained()->cascadeOnDelete();
            $table->date('period');
            $table->decimal('actual', 15, 2);
            $table->decimal('target_snapshot', 15, 2);
            $table->text('notes')->nullable();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['kpi_definition_id', 'period']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('kpi_measurements');
    }
};
