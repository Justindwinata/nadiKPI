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
        Schema::create('financial_records', function (Blueprint $table) {
            $table->id();
            $table->date('recorded_on');
            $table->enum('type', ['revenue', 'expense', 'budget']);
            $table->string('category');
            $table->string('department_code')->nullable();
            $table->decimal('amount', 15, 2);
            $table->string('description');
            $table->string('reference')->nullable()->unique();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('financial_records');
    }
};
