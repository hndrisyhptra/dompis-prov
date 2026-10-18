<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('role')->default('branch_admin')->index();
            $table->boolean('active')->default(false);
        });
        Schema::create('branches', function (Blueprint $table) {
            $table->id();
            $table->string('code', 40)->unique();
            $table->string('name');
            $table->string('regional')->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });
        Schema::create('user_branches', function (Blueprint $table) {
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->primary(['user_id', 'branch_id']);
        });
        Schema::create('branch_mappings', function (Blueprint $table) {
            $table->id();
            $table->string('bima_branch', 120);
            $table->string('sto_code', 40)->default('');
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->boolean('enabled')->default(true);
            $table->timestamps();
            $table->unique(['bima_branch', 'sto_code']);
        });
        Schema::create('work_orders', function (Blueprint $table) {
            $table->id();
            $table->string('source_system', 20)->default('BIMA');
            $id = $table->string('external_order_id', 191);
            if (Schema::getConnection()->getDriverName() === 'mysql') {
                $id->collation('utf8mb4_bin');
            }
            $table->foreignId('branch_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('source_sid')->nullable();
            $table->date('order_date')->nullable()->index();
            $table->string('source_branch_name')->nullable();
            $table->string('sto_code')->nullable();
            $table->string('regional')->nullable();
            $table->string('customer_name')->nullable();
            $table->text('installation_address')->nullable();
            $table->string('service_name')->nullable();
            $table->string('job_type')->nullable()->index();
            $table->string('bima_status')->nullable();
            $table->string('bima_final_status')->nullable();
            $table->date('bima_ps_date')->nullable();
            $table->json('source_bima_fields');
            $table->string('source_snapshot_hash', 64);
            $table->string('source_tab')->default('BIMA_RAW');
            $table->timestamp('last_pulled_at')->nullable();
            $table->string('operational_status')->default('NEW')->index();
            $table->string('verification_status')->default('UNVERIFIED');
            $table->unsignedInteger('revision')->default(0);
            $table->timestamp('last_progress_at')->nullable();
            $table->timestamp('field_done_at')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->timestamps();
            $table->unique(['source_system', 'external_order_id']);
            $table->index(['branch_id', 'operational_status']);
        });
        Schema::create('sync_jobs', function (Blueprint $table) {
            $table->id();
            $table->uuid('idempotency_key')->unique();
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->string('direction');
            $table->string('stage')->default('preview');
            $table->string('state')->default('queued')->index();
            $table->string('connection_hash', 64);
            $table->longText('snapshot')->nullable();
            $table->json('stats')->nullable();
            $table->string('error')->nullable();
            $table->unsignedInteger('attempts')->default(0);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
        });
        Schema::create('sync_job_rows', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sync_job_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('row_index');
            $table->string('external_key', 191)->nullable();
            $table->string('action');
            $table->json('field_changes')->nullable();
            $table->string('error')->nullable();
            $table->timestamp('applied_at')->nullable();
            $table->unique(['sync_job_id', 'row_index']);
        });
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('actor_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('action')->index();
            $table->string('entity_type');
            $table->unsignedBigInteger('entity_id');
            $table->json('metadata')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        foreach (['audit_logs', 'sync_job_rows', 'sync_jobs', 'work_orders', 'branch_mappings', 'user_branches', 'branches'] as $table) {
            Schema::dropIfExists($table);
        }
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['role']);
            $table->dropColumn(['role', 'active']);
        });
    }
};
