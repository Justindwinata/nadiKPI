<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('certification_batches', function (Blueprint $table) {
            $table->dateTime('assessment_completed_at')->nullable()->after('assessment_date');
            $table->dateTime('decision_at')->nullable()->after('assessment_completed_at');
            $table->dateTime('certificate_due_at')->nullable()->after('decision_at');
            $table->dateTime('completed_at')->nullable()->after('certificate_due_at');
        });

        Schema::create('certificate_issuances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('certification_batch_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('issued_count');
            $table->dateTime('issued_at');
            $table->string('reference', 100)->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['certification_batch_id', 'issued_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('certificate_issuances');

        Schema::table('certification_batches', function (Blueprint $table) {
            $table->dropColumn([
                'assessment_completed_at',
                'decision_at',
                'certificate_due_at',
                'completed_at',
            ]);
        });
    }
};
