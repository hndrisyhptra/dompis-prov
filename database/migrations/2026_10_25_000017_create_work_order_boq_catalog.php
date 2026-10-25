<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['designator_types', 'designators', 'packages', 'designator_package_prices', 'qe_boqs', 'qe_boq_items', 'qe_boq_histories'] as $table) {
            if (Schema::hasTable($table)) {
                throw new RuntimeException('Tabel '.$table.' sudah ada. Rekonsiliasi diperlukan; data tidak ditimpa.');
            }
        }
        Schema::create('designator_types', function (Blueprint $table) {
            $table->id('id_designator_type');
            $table->string('code')->unique();
            $table->string('name');
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true)->index();
            $table->foreignId('created_by')->nullable()->constrained('users', 'id_users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users', 'id_users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });
        Schema::create('designators', function (Blueprint $table) {
            $table->id('id_designator');
            $table->string('code')->unique();
            $table->text('item_name');
            $table->string('unit');
            $table->foreignId('designator_type_id')->nullable()->constrained('designator_types', 'id_designator_type')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users', 'id_users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users', 'id_users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });
        Schema::create('packages', function (Blueprint $table) {
            $table->id('id_package');
            $table->string('code')->unique();
            $table->string('name');
            $table->text('description')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users', 'id_users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users', 'id_users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });
        Schema::create('designator_package_prices', function (Blueprint $table) {
            $table->id('id_designator_package_price');
            $table->foreignId('designator_id')->constrained('designators', 'id_designator')->cascadeOnDelete();
            $table->foreignId('package_id')->constrained('packages', 'id_package')->cascadeOnDelete();
            $table->decimal('price', 15, 2);
            $table->foreignId('created_by')->nullable()->constrained('users', 'id_users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users', 'id_users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['designator_id', 'package_id']);
        });
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
        $reference = json_decode(file_get_contents(database_path('data/boq_catalog_20261025.json')), true, flags: JSON_THROW_ON_ERROR);
        foreach (['designator_types', 'designators', 'packages', 'designator_package_prices'] as $table) {
            foreach (array_chunk($reference[$table], 100) as $rows) {
                DB::table($table)->insert(array_map(function ($row) use ($table) {
                    if ($table === 'designators') {
                        unset($row['designator_category_id']);
                    }

                    return array_replace($row, ['created_by' => null, 'updated_by' => null]);
                }, $rows));
            }
        }
    }

    public function down(): void
    {
        foreach (['qe_boq_histories', 'qe_boq_items', 'qe_boqs', 'designator_package_prices', 'packages', 'designators', 'designator_types'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
