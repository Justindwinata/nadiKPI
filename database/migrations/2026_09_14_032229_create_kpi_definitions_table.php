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
        Schema::create('kpi_definitions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('department_id')->nullable()->constrained()->nullOnDelete();
            $table->string('code')->unique();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('unit', 30);
            $table->enum('direction', ['higher', 'lower'])->default('higher');
            $table->decimal('weight', 5, 2)->default(10);
            $table->decimal('target', 15, 2);
            $table->decimal('warning_threshold', 15, 2)->nullable();
            $table->string('cadence', 20)->default('monthly');
            $table->string('data_source')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('kpi_definitions');
    }
};
