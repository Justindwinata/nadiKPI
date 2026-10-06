<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('financial_records', function (Blueprint $table) {
            $table->dropIndex(['reversal_of_id']);
            $table->unique('reversal_of_id');
        });
    }

    public function down(): void
    {
        Schema::table('financial_records', function (Blueprint $table) {
            $table->dropUnique(['reversal_of_id']);
            $table->index('reversal_of_id');
        });
    }
};
