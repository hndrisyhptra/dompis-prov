<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('regions') || Schema::hasTable('service_areas')) {
            throw new RuntimeException('Master region/STO sudah ada. Rekonsiliasi terlebih dahulu; tidak ditimpa otomatis.');
        }
        $source = json_decode(file_get_contents(database_path('data/olo_locations_20261018.json')), true, flags: JSON_THROW_ON_ERROR);
        Schema::create('regions', function (Blueprint $table) {
            $table->id('id_regions');
            $table->string('code')->unique();
            $table->string('name');
            $table->boolean('is_active')->default(true)->index();
            $table->foreignId('id_users_created_by')->nullable()->constrained('users', 'id_users')->nullOnDelete();
            $table->foreignId('id_users_updated_by')->nullable()->constrained('users', 'id_users')->nullOnDelete();
            $table->json('source_metadata')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
        Schema::create('service_areas', function (Blueprint $table) {
            $table->id('id_service_areas');
            $table->string('workzone', 20)->unique();
            $table->string('name', 100);
            // Reconcile legacy branch IDs against the supplied region label; unresolved IDs remain null.
            $table->foreignId('id_branches')->nullable()->constrained('branches', 'id_branches')->restrictOnDelete();
            $table->foreignId('id_regions')->nullable()->constrained('regions', 'id_regions')->nullOnDelete();
            $table->boolean('is_active')->default(true)->index();
            $table->foreignId('id_users_created_by')->nullable()->constrained('users', 'id_users')->nullOnDelete();
            $table->foreignId('id_users_updated_by')->nullable()->constrained('users', 'id_users')->nullOnDelete();
            $table->json('source_metadata')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['id_branches', 'is_active']);
        });
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('id_regions')->nullable()->constrained('regions', 'id_regions')->restrictOnDelete();
            $table->foreignId('id_service_areas')->nullable()->constrained('service_areas', 'id_service_areas')->restrictOnDelete();
        });
        $branches = DB::table('branches')->whereNull('deleted_at')->get()->keyBy('id_branches');
        $regions = collect($source['regions'])->keyBy('id_region');
        DB::transaction(function () use ($source, $branches, $regions) {
            foreach ($source['regions'] as $row) {
                DB::table('regions')->insert([
                    'id_regions' => $row['id_region'], 'code' => $row['code'], 'name' => $row['name'], 'is_active' => $row['is_active'],
                    'source_metadata' => json_encode($row, JSON_THROW_ON_ERROR),
                    'created_at' => $row['created_at'], 'updated_at' => $row['updated_at'], 'deleted_at' => $row['deleted_at'],
                ]);
            }
            foreach (array_chunk($source['service_areas'], 100) as $chunk) {
                DB::table('service_areas')->insert(array_map(fn ($row) => [
                    'id_service_areas' => $row['id_service_area'], 'workzone' => $row['workzone'], 'name' => $row['name'],
                    'id_regions' => $row['region_id'],
                    'id_branches' => ($branches->get($row['branch_id'])?->regional === ($regions->get($row['region_id'])['name'] ?? null)) ? $branches->get($row['branch_id'])?->id_branches : null,
                    'is_active' => $row['is_active'],
                    'source_metadata' => json_encode($row, JSON_THROW_ON_ERROR),
                    'created_at' => $row['created_at'], 'updated_at' => $row['updated_at'], 'deleted_at' => $row['deleted_at'],
                ], $chunk));
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['id_service_areas']);
            $table->dropForeign(['id_regions']);
            $table->dropColumn(['id_service_areas', 'id_regions']);
        });
        Schema::dropIfExists('service_areas');
        Schema::dropIfExists('regions');
    }
};
