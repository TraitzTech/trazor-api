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
        Schema::table('task_intern', function (Blueprint $table) {
            $table->enum('status', ['pending', 'in_progress', 'done'])->default('pending')->after('intern_id');
            $table->timestamp('started_at')->nullable()->after('status');
            $table->timestamp('completed_at')->nullable()->after('started_at');
            $table->text('intern_notes')->nullable()->after('completed_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('task_intern', function (Blueprint $table) {
            $table->dropColumn(['status', 'started_at', 'completed_at', 'intern_notes']);
        });
    }
};
