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
        Schema::create('it_incidents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('it_service_id')->constrained()->cascadeOnDelete();
            $table->string('reference')->unique();
            $table->enum('severity', ['low', 'medium', 'high', 'critical']);
            $table->enum('status', ['open', 'investigating', 'resolved'])->default('open');
            $table->dateTime('started_at');
            $table->dateTime('resolved_at')->nullable();
            $table->text('summary');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('it_incidents');
    }
};
