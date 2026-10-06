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
        Schema::create('it_services', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('owner');
            $table->decimal('target_uptime', 5, 2)->default(99.50);
            $table->enum('status', ['operational', 'degraded', 'maintenance'])->default('operational');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('it_services');
    }
};
