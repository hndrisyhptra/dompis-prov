<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('work_order_priorities', function (Blueprint $table) {
            $table->bigIncrements('id_work_order_priorities');
            $table->foreignId('id_work_orders')->constrained('work_orders', 'id_work_orders')->restrictOnDelete();
            $table->foreignId('id_branches')->nullable()->constrained('branches', 'id_branches')->restrictOnDelete();
            $table->foreignId('id_users')->constrained('users', 'id_users')->restrictOnDelete();
            $table->string('scope_key', 64);
            $table->uuid('selection_token')->unique();
            $table->timestamps();
            $table->unique(['id_work_orders', 'scope_key'], 'priority_order_scope_unique');
            $table->index(['scope_key', 'created_at']);
            $table->index(['id_branches', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('work_order_priorities');
    }
};
