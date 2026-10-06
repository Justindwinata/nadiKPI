<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('report_snapshots', function (Blueprint $table) {
            $table->id();
            $table->string('reference', 90)->unique();
            $table->string('report_type', 50);
            $table->string('title', 180);
            $table->foreignId('department_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('management_review_id')->nullable()->constrained()->nullOnDelete();
            $table->date('period_start');
            $table->date('period_end');
            $table->dateTime('generated_at');
            $table->foreignId('generated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->char('content_hash', 64);
            $table->json('payload');
            $table->json('source_manifest')->nullable();
            $table->unsignedSmallInteger('schema_version')->default(1);
            $table->timestamps();
            $table->index(['report_type', 'period_end']);
            $table->index(['department_id', 'period_end']);
            $table->index(['generated_by', 'generated_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('report_snapshots');
    }
};
