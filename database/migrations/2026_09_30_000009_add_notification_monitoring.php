<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('risk_signal_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('action_item_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('management_review_id')->nullable()->constrained()->nullOnDelete();
            $table->string('fingerprint', 190);
            $table->string('type', 60);
            $table->string('severity', 20)->default('medium');
            $table->string('title', 180);
            $table->text('message');
            $table->string('action_url', 255)->nullable();
            $table->json('data')->nullable();
            $table->dateTime('read_at')->nullable();
            $table->dateTime('acknowledged_at')->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'fingerprint']);
            $table->index(['user_id', 'read_at', 'created_at']);
            $table->index(['user_id', 'severity', 'created_at']);
        });

        Schema::table('risk_signals', function (Blueprint $table) {
            $table->dateTime('auto_escalated_at')->nullable()->after('escalated_by');
            $table->index(['status', 'severity', 'acknowledged_at', 'detected_at'], 'risk_signal_monitor_idx');
        });
    }

    public function down(): void
    {
        Schema::table('risk_signals', function (Blueprint $table) {
            $table->dropIndex('risk_signal_monitor_idx');
            $table->dropColumn('auto_escalated_at');
        });

        Schema::dropIfExists('user_notifications');
    }
};
