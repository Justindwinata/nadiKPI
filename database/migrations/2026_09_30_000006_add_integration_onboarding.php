<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('data_import_batches', function (Blueprint $table) {
            $table->string('dataset_type', 80)->nullable()->after('dataset_name');
            $table->json('column_mapping')->nullable()->after('sha256');
            $table->json('mapping_defaults')->nullable()->after('column_mapping');
            $table->json('source_headers')->nullable()->after('mapping_defaults');
            $table->json('validation_summary')->nullable()->after('errors');
            $table->unsignedInteger('duplicate_rows')->default(0)->after('rejected_rows');
            $table->unsignedInteger('inserted_rows')->default(0)->after('duplicate_rows');
            $table->unsignedInteger('updated_rows')->default(0)->after('inserted_rows');
            $table->unsignedInteger('skipped_rows')->default(0)->after('updated_rows');
            $table->dateTime('staged_at')->nullable()->after('imported_at');
            $table->dateTime('published_at')->nullable()->after('staged_at');
            $table->foreignId('published_by')->nullable()->after('published_at')->constrained('users')->nullOnDelete();
            $table->index(['dataset_type', 'status', 'imported_at']);
            $table->index(['data_source_id', 'dataset_type', 'sha256'], 'import_source_dataset_hash_idx');
        });

        Schema::create('integration_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('data_source_id')->constrained()->cascadeOnDelete();
            $table->string('dataset_type', 80);
            $table->string('name', 160);
            $table->json('column_mapping');
            $table->json('defaults')->nullable();
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['data_source_id', 'dataset_type', 'name'], 'integration_profile_unique');
        });

        Schema::create('integration_staging_rows', function (Blueprint $table) {
            $table->id();
            $table->foreignId('data_import_batch_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('row_number');
            $table->string('external_key', 255)->nullable();
            $table->char('row_hash', 64)->nullable();
            $table->json('raw_payload');
            $table->json('normalized_payload')->nullable();
            $table->string('status', 40)->default('pending');
            $table->string('planned_action', 40)->nullable();
            $table->json('validation_errors')->nullable();
            $table->string('published_entity_type', 255)->nullable();
            $table->unsignedBigInteger('published_entity_id')->nullable();
            $table->timestamps();
            $table->unique(['data_import_batch_id', 'row_number'], 'staging_batch_row_unique');
            $table->index(['data_import_batch_id', 'status']);
            $table->index(['external_key', 'status']);
        });

        Schema::create('integration_record_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('data_source_id')->constrained()->cascadeOnDelete();
            $table->string('dataset_type', 80);
            $table->string('external_key', 255);
            $table->string('entity_type', 255);
            $table->unsignedBigInteger('entity_id');
            $table->char('row_hash', 64);
            $table->foreignId('last_import_batch_id')->nullable()->constrained('data_import_batches')->nullOnDelete();
            $table->dateTime('last_seen_at');
            $table->timestamps();
            $table->unique(['data_source_id', 'dataset_type', 'external_key'], 'integration_record_external_unique');
            $table->index(['entity_type', 'entity_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('integration_record_links');
        Schema::dropIfExists('integration_staging_rows');
        Schema::dropIfExists('integration_profiles');

        Schema::table('data_import_batches', function (Blueprint $table) {
            $table->dropIndex('import_source_dataset_hash_idx');
            $table->dropIndex(['dataset_type', 'status', 'imported_at']);
            $table->dropConstrainedForeignId('published_by');
            $table->dropColumn([
                'dataset_type', 'column_mapping', 'mapping_defaults', 'source_headers', 'validation_summary',
                'duplicate_rows', 'inserted_rows', 'updated_rows', 'skipped_rows',
                'staged_at', 'published_at',
            ]);
        });
    }
};
