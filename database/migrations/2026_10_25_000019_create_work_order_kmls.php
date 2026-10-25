<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('work_order_kmls', function (Blueprint $table) {
            $table->id('id_work_order_kmls');
            $table->foreignId('id_work_orders')->constrained('work_orders', 'id_work_orders')->restrictOnDelete();
            $table->unsignedInteger('revision');
            $table->string('original_name');
            $table->string('file_path');
            $table->string('file_hash', 64);
            $table->unsignedInteger('feature_count');
            $table->unsignedInteger('vertex_count');
            $table->json('geojson');
            $table->foreignId('id_users')->nullable()->constrained('users', 'id_users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['id_work_orders', 'revision']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('work_order_kmls');
    }
};
