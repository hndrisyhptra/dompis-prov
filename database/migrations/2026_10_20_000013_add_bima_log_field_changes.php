<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bima_task_logs', function (Blueprint $table) {
            $table->longText('field_changes')->nullable(); // Encrypted array; source changes may contain customer data.
        });
    }

    public function down(): void
    {
        Schema::table('bima_task_logs', function (Blueprint $table) {
            $table->dropColumn('field_changes');
        });
    }
};
