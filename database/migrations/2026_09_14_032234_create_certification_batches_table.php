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
        Schema::create('certification_batches', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->foreignId('certification_scheme_id')->constrained()->restrictOnDelete();
            $table->foreignId('tuk_id')->constrained()->restrictOnDelete();
            $table->foreignId('assessor_id')->nullable()->constrained()->nullOnDelete();
            $table->date('assessment_date');
            $table->unsignedInteger('total_assesi')->default(0);
            $table->unsignedInteger('passed')->default(0);
            $table->unsignedInteger('failed')->default(0);
            $table->unsignedInteger('pending')->default(0);
            $table->unsignedInteger('certificates_issued')->default(0);
            $table->unsignedInteger('issued_on_time')->default(0);
            $table->decimal('revenue', 15, 2)->default(0);
            $table->enum('status', ['planned', 'document_review', 'assessment', 'decision', 'completed'])->default('planned');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('certification_batches');
    }
};
