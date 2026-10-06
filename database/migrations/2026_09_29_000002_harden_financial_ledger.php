<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('financial_records', function (Blueprint $table) {
            $table->enum('entry_kind', ['normal', 'reversal'])->default('normal')->after('type');
            $table->unsignedBigInteger('reversal_of_id')->nullable()->after('entry_kind');
            $table->string('source_type', 40)->default('manual')->after('reference');
            $table->unsignedBigInteger('source_id')->nullable()->after('source_type');
            $table->foreignId('recorded_by')->nullable()->after('source_id')->constrained('users')->nullOnDelete();
            $table->index(['recorded_on', 'type']);
            $table->index(['source_type', 'source_id']);
            $table->index('reversal_of_id');
        });

        Schema::create('finance_invoices', function (Blueprint $table) {
            $table->id();
            $table->string('invoice_number', 100)->unique();
            $table->date('issued_on');
            $table->date('due_on');
            $table->string('customer_name', 160);
            $table->string('category', 100)->default('Jasa sertifikasi');
            $table->string('department_code', 30)->nullable();
            $table->decimal('amount', 15, 2);
            $table->string('description', 255);
            $table->enum('status', ['issued', 'partially_paid', 'paid', 'void'])->default('issued');
            $table->foreignId('revenue_record_id')->nullable()->unique()->constrained('financial_records')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('voided_at')->nullable();
            $table->foreignId('voided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('void_reason', 500)->nullable();
            $table->timestamps();
            $table->index(['issued_on', 'due_on']);
            $table->index(['status', 'due_on']);
        });

        Schema::create('finance_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('finance_invoice_id')->constrained('finance_invoices')->restrictOnDelete();
            $table->date('paid_on');
            $table->decimal('amount', 15, 2);
            $table->string('reference', 100)->unique();
            $table->string('notes', 500)->nullable();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reversed_at')->nullable();
            $table->foreignId('reversed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('reversal_reason', 500)->nullable();
            $table->timestamps();
            $table->index(['paid_on', 'reversed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('finance_payments');
        Schema::dropIfExists('finance_invoices');

        Schema::table('financial_records', function (Blueprint $table) {
            $table->dropIndex(['recorded_on', 'type']);
            $table->dropIndex(['source_type', 'source_id']);
            $table->dropIndex(['reversal_of_id']);
            $table->dropConstrainedForeignId('recorded_by');
            $table->dropColumn(['entry_kind', 'reversal_of_id', 'source_type', 'source_id']);
        });
    }
};
