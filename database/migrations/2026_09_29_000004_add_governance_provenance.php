<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('certification_schemes', function (Blueprint $table) {
            $table->date('valid_until')->nullable()->after('is_active');
            $table->string('evidence_reference', 255)->nullable()->after('valid_until');
        });

        Schema::table('tuks', function (Blueprint $table) {
            $table->date('verification_valid_until')->nullable()->after('monthly_capacity');
            $table->string('evidence_reference', 255)->nullable()->after('verification_valid_until');
        });

        Schema::create('data_sources', function (Blueprint $table) {
            $table->id();
            $table->string('code', 60)->unique();
            $table->string('name', 160);
            $table->string('source_type', 40)->default('internal');
            $table->unsignedTinyInteger('authority_rank')->default(50);
            $table->string('owner_name', 160)->nullable();
            $table->string('location', 500)->nullable();
            $table->boolean('is_active')->default(true);
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('data_import_batches', function (Blueprint $table) {
            $table->id();
            $table->string('reference', 80)->unique();
            $table->foreignId('data_source_id')->nullable()->constrained()->nullOnDelete();
            $table->string('dataset_name', 160);
            $table->string('file_name', 255);
            $table->char('sha256', 64);
            $table->dateTime('imported_at');
            $table->unsignedInteger('total_rows')->default(0);
            $table->unsignedInteger('accepted_rows')->default(0);
            $table->unsignedInteger('rejected_rows')->default(0);
            $table->string('status', 40)->default('completed');
            $table->string('reconciliation_status', 40)->default('pending');
            $table->json('errors')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['dataset_name', 'imported_at']);
            $table->index(['reconciliation_status', 'imported_at']);
        });

        Schema::table('kpi_measurements', function (Blueprint $table) {
            $table->string('source_type', 40)->default('manual')->after('notes');
            $table->string('source_reference', 120)->nullable()->after('source_type');
            $table->foreignId('data_import_batch_id')->nullable()->after('source_reference')->constrained()->nullOnDelete();
        });

        Schema::create('compliance_obligations', function (Blueprint $table) {
            $table->id();
            $table->string('code', 80)->unique();
            $table->string('title', 180);
            $table->string('category', 60);
            $table->string('authority', 160)->nullable();
            $table->date('valid_from')->nullable();
            $table->date('valid_until')->nullable();
            $table->string('status', 40)->default('active');
            $table->string('owner_name', 160);
            $table->string('evidence_reference', 500)->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('compliance_findings', function (Blueprint $table) {
            $table->id();
            $table->string('reference', 80)->unique();
            $table->string('source', 160);
            $table->string('category', 80);
            $table->string('severity', 20)->default('medium');
            $table->string('title', 180);
            $table->text('description');
            $table->string('status', 40)->default('open');
            $table->string('owner_name', 160);
            $table->dateTime('opened_at');
            $table->dateTime('due_at')->nullable();
            $table->dateTime('closed_at')->nullable();
            $table->text('closure_evidence')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['status', 'due_at']);
        });

        Schema::create('corrective_actions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('compliance_finding_id')->constrained()->cascadeOnDelete();
            $table->string('title', 180);
            $table->text('description');
            $table->string('owner_name', 160);
            $table->date('due_date');
            $table->string('status', 40)->default('open');
            $table->text('evidence')->nullable();
            $table->dateTime('completed_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['status', 'due_date']);
        });

        Schema::create('certification_appeals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('certification_batch_id')->constrained()->cascadeOnDelete();
            $table->string('reference', 80)->unique();
            $table->string('appellant_reference', 120);
            $table->dateTime('received_at');
            $table->dateTime('due_at')->nullable();
            $table->text('reason');
            $table->string('status', 40)->default('received');
            $table->string('owner_name', 160);
            $table->string('decision', 40)->nullable();
            $table->text('resolution_summary')->nullable();
            $table->dateTime('decision_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['status', 'due_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('certification_appeals');
        Schema::dropIfExists('corrective_actions');
        Schema::dropIfExists('compliance_findings');
        Schema::dropIfExists('compliance_obligations');

        Schema::table('kpi_measurements', function (Blueprint $table) {
            $table->dropConstrainedForeignId('data_import_batch_id');
            $table->dropColumn(['source_type', 'source_reference']);
        });

        Schema::dropIfExists('data_import_batches');
        Schema::dropIfExists('data_sources');

        Schema::table('tuks', function (Blueprint $table) {
            $table->dropColumn(['verification_valid_until', 'evidence_reference']);
        });

        Schema::table('certification_schemes', function (Blueprint $table) {
            $table->dropColumn(['valid_until', 'evidence_reference']);
        });
    }
};
