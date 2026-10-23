<?php

use App\Services\WorkOrderDerivedFields;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mapping_order', function (Blueprint $table) {
            $table->bigIncrements('id_mapping_order');
            $column = $table->string('status_order', 511)->unique();
            if (DB::getDriverName() === 'mysql') {
                $column->collation('utf8mb4_bin');
            }
            $table->enum('mapping_order', ['STUCK', 'PS', 'CANCLWORK']);
            $table->boolean('active')->default(true);
            $table->timestamps();
        });
        Schema::table('work_orders', function (Blueprint $table) {
            $table->string('status_bima')->nullable();
            $table->text('status_order')->nullable()->change();
            $table->string('mapping_order', 20)->nullable();
            $table->foreignId('id_mapping_order')->nullable()->constrained('mapping_order', 'id_mapping_order')->restrictOnDelete();
            $table->json('device_overrides')->nullable();
        });
        Schema::create('bima_task_logs', function (Blueprint $table) {
            $table->bigIncrements('id_bima_task_logs');
            $table->foreignId('id_work_orders')->constrained('work_orders', 'id_work_orders')->restrictOnDelete();
            $table->foreignId('id_sync_jobs')->nullable()->constrained('sync_jobs', 'id_sync_jobs')->restrictOnDelete();
            $table->foreignId('id_users')->nullable()->constrained('users', 'id_users')->restrictOnDelete();
            $table->string('old_task')->nullable();
            $table->string('new_task')->nullable();
            $table->string('old_status_bima')->nullable();
            $table->string('new_status_bima')->nullable();
            $table->text('old_status_order')->nullable();
            $table->text('new_status_order')->nullable();
            $table->timestamp('changed_at');
            $table->unique(['id_sync_jobs', 'id_work_orders']);
        });
        DB::table('work_orders')->orderBy('id_work_orders')->chunkById(100, function ($rows) {
            foreach ($rows as $row) {
                DB::table('work_orders')->where('id_work_orders', $row->id_work_orders)->update(
                    ['status_bima' => $row->status_order, 'status_order' => WorkOrderDerivedFields::status($row->task_bima, $row->status_order)]
                    + WorkOrderDerivedFields::dates($row->tgl_order));
            }
        }, 'id_work_orders');
    }

    public function down(): void
    {
        Schema::dropIfExists('bima_task_logs');
        DB::table('work_orders')->update(['status_order' => DB::raw('status_bima')]);
        Schema::table('work_orders', function (Blueprint $table) {
            $table->dropForeign(['id_mapping_order']);
            $table->dropColumn(['status_bima', 'mapping_order', 'id_mapping_order', 'device_overrides']);
            $table->string('status_order')->nullable()->change();
        });
        Schema::dropIfExists('mapping_order');
    }
};
