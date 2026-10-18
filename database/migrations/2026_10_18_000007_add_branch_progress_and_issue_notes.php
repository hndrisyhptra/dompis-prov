<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('work_orders', function (Blueprint $table) {
            $table->string('progress_category', 40)->nullable()->index();
            $table->timestamp('branch_completed_at')->nullable();
            $table->index(['id_branches', 'branch_completed_at']);
        });
        Schema::table('wo_status_events', function (Blueprint $table) {
            $table->string('old_progress_category', 40)->nullable();
            $table->string('new_progress_category', 40)->nullable();
        });
        Schema::create('wo_issue_notes', function (Blueprint $table) {
            $table->id('id_wo_issue_notes');
            $table->foreignId('id_wo_issues')->constrained('wo_issues', 'id_wo_issues')->restrictOnDelete();
            $table->foreignId('id_users')->constrained('users', 'id_users')->restrictOnDelete();
            $table->text('note');
            $table->timestamp('created_at')->useCurrent();
        });
        // Preserve all legacy values and timestamps. Only unambiguous categories/date evidence are added.
        DB::table('work_orders')->where('operational_status', 'SURVEY')->update(['progress_category' => 'SURVEY']);
        DB::table('work_orders')->where('operational_status', 'COMPLETED')->update(['progress_category' => 'CLOSE', 'branch_completed_at' => DB::raw('verified_at')]);
        DB::table('work_orders')->where('operational_status', 'CANCELLED')->update(['progress_category' => 'CANCEL']);
    }

    public function down(): void
    {
        Schema::dropIfExists('wo_issue_notes');
        Schema::table('wo_status_events', fn (Blueprint $table) => $table->dropColumn(['old_progress_category', 'new_progress_category']));
        Schema::table('work_orders', function (Blueprint $table) {
            $table->dropIndex(['progress_category']);
            $table->dropIndex(['id_branches', 'branch_completed_at']);
            $table->dropColumn(['progress_category', 'branch_completed_at']);
        });
    }
};
