<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wo_status_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('work_order_id')->constrained()->restrictOnDelete();
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->string('old_status');
            $table->string('new_status');
            $table->unsignedInteger('revision');
            $table->text('notes');
            $table->timestamp('created_at')->useCurrent();
            $table->unique(['work_order_id', 'revision']);
        });
        Schema::create('wo_issues', function (Blueprint $table) {
            $table->id();
            $table->foreignId('work_order_id')->constrained()->restrictOnDelete();
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->string('category', 40);
            $table->text('description');
            $table->string('pic', 120);
            $table->date('eta')->nullable();
            $table->string('status')->default('OPEN');
            $table->timestamp('resolved_at')->nullable();
            $table->text('resolution')->nullable();
            $table->timestamps();
            $table->index(['work_order_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wo_issues');
        Schema::dropIfExists('wo_status_events');
    }
};
