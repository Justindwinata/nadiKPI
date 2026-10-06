<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('must_change_password')->default(false)->after('is_active');
            $table->timestamp('password_changed_at')->nullable()->after('must_change_password');
            $table->timestamp('last_login_at')->nullable()->after('password_changed_at');
            $table->string('last_login_ip', 45)->nullable()->after('last_login_at');
            $table->timestamp('deactivated_at')->nullable()->after('last_login_ip');
            $table->foreignId('deactivated_by')->nullable()->after('deactivated_at')->constrained('users')->nullOnDelete();
        });

        Schema::create('user_permissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('permission', 120);
            $table->boolean('allowed');
            $table->string('reason', 500)->nullable();
            $table->foreignId('granted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['user_id', 'permission']);
            $table->index(['permission', 'allowed']);
        });

        Schema::create('authentication_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('email')->nullable();
            $table->string('event', 60);
            $table->boolean('successful')->default(false);
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->timestamp('occurred_at')->useCurrent();
            $table->index(['event', 'occurred_at']);
            $table->index(['email', 'occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('authentication_events');
        Schema::dropIfExists('user_permissions');

        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('deactivated_by');
            $table->dropColumn([
                'must_change_password',
                'password_changed_at',
                'last_login_at',
                'last_login_ip',
                'deactivated_at',
            ]);
        });
    }
};
