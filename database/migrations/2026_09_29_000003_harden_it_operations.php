<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('it_incidents', function (Blueprint $table) {
            $table->dateTime('acknowledged_at')->nullable()->after('started_at');
            $table->foreignId('resolved_by')->nullable()->after('resolved_at')->constrained('users')->nullOnDelete();
            $table->text('resolution_summary')->nullable()->after('summary');
            $table->text('root_cause')->nullable()->after('resolution_summary');
            $table->index(['it_service_id', 'started_at']);
            $table->index(['status', 'resolved_at']);
        });

        Schema::create('data_quality_runs', function (Blueprint $table) {
            $table->id();
            $table->string('reference')->unique();
            $table->string('dataset_name');
            $table->string('source_system');
            $table->dateTime('assessed_at');
            $table->unsignedBigInteger('total_records');
            $table->unsignedBigInteger('valid_records');
            $table->unsignedBigInteger('missing_required_records')->default(0);
            $table->unsignedBigInteger('duplicate_records')->default(0);
            $table->unsignedBigInteger('freshness_failures')->default(0);
            $table->text('notes')->nullable();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['assessed_at', 'dataset_name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('data_quality_runs');

        Schema::table('it_incidents', function (Blueprint $table) {
            $table->dropIndex(['it_service_id', 'started_at']);
            $table->dropIndex(['status', 'resolved_at']);
            $table->dropConstrainedForeignId('resolved_by');
            $table->dropColumn(['acknowledged_at', 'resolution_summary', 'root_cause']);
        });
    }
};
