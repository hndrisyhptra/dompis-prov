<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $tables = ['qe_boqs', 'qe_boq_items', 'qe_boq_histories'];
        $existing = array_values(array_filter($tables, fn ($table) => Schema::hasTable($table)));
        if (count($existing) === count($tables)) {
            return;
        }
        if ($existing) {
            throw new RuntimeException('Sebagian tabel BOQ masih ada. Pemulihan dihentikan tanpa perubahan; rekonsiliasi backup dan relasi data terlebih dahulu.');
        }
        foreach (['work_orders' => 'id_work_orders', 'users' => 'id_users', 'packages' => 'id_package', 'designators' => 'id_designator'] as $table => $key) {
            if (! Schema::hasColumn($table, $key)) {
                throw new RuntimeException('Master/prasyarat '.$table.'.'.$key.' belum tersedia. Tidak ada tabel yang diubah.');
            }
        }

        // Restore the existing 000017 contract; never reset master prices or migration history.
        Schema::create('qe_boqs', function (Blueprint $table) {
            $table->id('id_qe_boqs');
            $table->foreignId('id_work_orders')->unique()->constrained('work_orders', 'id_work_orders')->restrictOnDelete();
            $table->foreignId('package_id')->constrained('packages', 'id_package')->restrictOnDelete();
            $table->string('source', 30)->default('import');
            $table->string('status', 20)->default('ready');
            $table->unsignedInteger('item_count')->default(0);
            $table->decimal('grand_total', 18, 2)->default(0);
            $table->unsignedInteger('revision')->default(1);
            $table->string('file_hash', 64);
            $table->string('content_hash', 64);
            $table->string('file_path');
            $table->string('original_name');
            $table->foreignId('created_by')->nullable()->constrained('users', 'id_users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users', 'id_users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['status', 'source']);
        });
        Schema::create('qe_boq_items', function (Blueprint $table) {
            $table->id('id_qe_boq_items');
            $table->foreignId('id_qe_boqs')->constrained('qe_boqs', 'id_qe_boqs')->restrictOnDelete();
            $table->foreignId('designator_id')->constrained('designators', 'id_designator')->restrictOnDelete();
            $table->string('designator_code');
            $table->text('item_name');
            $table->string('unit', 255);
            $table->string('type', 30);
            $table->decimal('qty', 15, 3);
            $table->decimal('unit_price', 18, 2)->default(0);
            $table->decimal('total_price', 18, 2)->default(0);
            $table->timestamps();
            $table->softDeletes();
            $table->index(['id_qe_boqs', 'type']);
            $table->index(['id_qe_boqs', 'designator_id']);
        });
        Schema::create('qe_boq_histories', function (Blueprint $table) {
            $table->id('id_qe_boq_histories');
            $table->foreignId('id_qe_boqs')->constrained('qe_boqs', 'id_qe_boqs')->restrictOnDelete();
            $table->foreignId('id_users')->nullable()->constrained('users', 'id_users')->nullOnDelete();
            $table->string('event_type', 40);
            $table->json('before_data')->nullable();
            $table->json('after_data')->nullable();
            $table->text('note')->nullable();
            $table->timestamps();
            $table->index(['id_qe_boqs', 'created_at']);
        });
        // Make BOQ available only after all dependent tables exist (000020 contract).
        Schema::table('qe_boqs', function (Blueprint $table) {
            $table->decimal('material_total', 18, 2)->default(0);
            $table->decimal('labor_total', 18, 2)->default(0);
        });
    }

    public function down(): void
    {
        // Recovery must not delete restored data. Original 000017 owns these tables.
    }
};
