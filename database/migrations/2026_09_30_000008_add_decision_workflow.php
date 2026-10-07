<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('risk_signals', function (Blueprint $table) {
            $table->id();
            $table->string('fingerprint', 190)->unique();
            $table->string('rule_code', 80);
            $table->string('category', 60);
            $table->string('severity', 20)->default('medium');
            $table->string('status', 30)->default('open');
            $table->foreignId('department_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('kpi_definition_id')->nullable()->constrained()->nullOnDelete();
            $table->string('source_type', 120);
            $table->unsignedBigInteger('source_id')->nullable();
            $table->string('source_reference', 160)->nullable();
            $table->string('title', 180);
            $table->text('description');
            $table->dateTime('detected_at');
            $table->dateTime('due_at')->nullable();
            $table->dateTime('last_observed_at');
            $table->dateTime('acknowledged_at')->nullable();
            $table->foreignId('acknowledged_by')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedTinyInteger('escalation_level')->default(0);
            $table->dateTime('escalated_at')->nullable();
            $table->foreignId('escalated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('resolved_at')->nullable();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('resolution_note')->nullable();
            $table->timestamps();
            $table->index(['status', 'severity', 'detected_at']);
            $table->index(['department_id', 'status']);
            $table->index(['source_type', 'source_id']);
        });

        Schema::table('action_items', function (Blueprint $table) {
            $table->foreignId('risk_signal_id')->nullable()->after('kpi_definition_id')->constrained()->nullOnDelete();
            $table->string('decision_reference', 100)->nullable()->after('description');
            $table->dateTime('acknowledged_at')->nullable()->after('due_date');
            $table->foreignId('acknowledged_by')->nullable()->after('acknowledged_at')->constrained('users')->nullOnDelete();
            $table->unsignedTinyInteger('escalation_level')->default(0)->after('acknowledged_by');
            $table->dateTime('escalated_at')->nullable()->after('escalation_level');
            $table->foreignId('escalated_by')->nullable()->after('escalated_at')->constrained('users')->nullOnDelete();
            $table->text('resolution_note')->nullable()->after('completed_at');
            $table->string('resolution_evidence', 500)->nullable()->after('resolution_note');
            $table->foreignId('updated_by')->nullable()->after('created_by')->constrained('users')->nullOnDelete();
            $table->index(['risk_signal_id', 'status']);
        });

        Schema::create('management_reviews', function (Blueprint $table) {
            $table->id();
            $table->string('reference', 80)->unique();
            $table->string('title', 180);
            $table->date('period_start');
            $table->date('period_end');
            $table->dateTime('meeting_at')->nullable();
            $table->string('chair_name', 160);
            $table->string('status', 30)->default('draft');
            $table->text('summary')->nullable();
            $table->text('decisions')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('approved_at')->nullable();
            $table->dateTime('closed_at')->nullable();
            $table->timestamps();
            $table->index(['status', 'period_end']);
        });

        Schema::create('management_review_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('management_review_id')->constrained()->cascadeOnDelete();
            $table->foreignId('risk_signal_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('action_item_id')->nullable()->constrained()->nullOnDelete();
            $table->string('title', 180);
            $table->string('owner_name', 160)->nullable();
            $table->date('due_date')->nullable();
            $table->text('decision')->nullable();
            $table->string('status', 30)->default('open');
            $table->timestamps();
            $table->unique(['management_review_id', 'risk_signal_id'], 'mri_review_risk_unique');
            $table->index(['management_review_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('management_review_items');
        Schema::dropIfExists('management_reviews');

        Schema::table('action_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('risk_signal_id');
            $table->dropConstrainedForeignId('acknowledged_by');
            $table->dropConstrainedForeignId('escalated_by');
            $table->dropConstrainedForeignId('updated_by');
            $table->dropColumn([
                'decision_reference', 'acknowledged_at', 'escalation_level', 'escalated_at',
                'resolution_note', 'resolution_evidence',
            ]);
        });

        Schema::dropIfExists('risk_signals');
    }
};
