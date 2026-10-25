<?php

use App\Support\MaterialReportFields;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('telegram_identities', function (Blueprint $table) {
            $table->id('id_telegram_identities');
            $table->foreignId('id_users')->unique()->constrained('users', 'id_users')->restrictOnDelete();
            $table->unsignedBigInteger('telegram_user_id')->unique();
            $table->boolean('active')->default(true);
            $table->timestamps();
        });
        Schema::create('telegram_chats', function (Blueprint $table) {
            $table->id('id_telegram_chats');
            $table->bigInteger('telegram_chat_id')->unique();
            $table->string('chat_type', 20);
            $table->foreignId('id_branches')->nullable()->constrained('branches', 'id_branches')->restrictOnDelete();
            $table->boolean('active')->default(true);
            $table->timestamps();
        });
        Schema::create('telegram_updates', function (Blueprint $table) {
            $table->id('id_telegram_updates');
            $table->unsignedBigInteger('telegram_update_id')->unique();
            $table->longText('payload'); // Encrypted cast. Never put the raw report in logs or queued payloads.
            $table->string('state', 20)->default('pending')->index();
            $table->string('error_code', 80)->nullable();
            $table->unsignedInteger('attempts')->default(0);
            $table->timestamp('next_attempt_at')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();
        });
        Schema::create('material_reports', function (Blueprint $table) {
            $table->id('id_material_reports');
            $table->string('report_number', 26)->unique();
            $table->foreignId('id_work_orders')->constrained('work_orders', 'id_work_orders')->restrictOnDelete();
            $table->foreignId('id_branches')->constrained('branches', 'id_branches')->restrictOnDelete();
            $table->foreignId('id_users')->nullable()->constrained('users', 'id_users')->nullOnDelete();
            $table->foreignId('id_telegram_updates')->unique()->constrained('telegram_updates', 'id_telegram_updates')->restrictOnDelete();
            $table->foreignId('id_supersedes_material_reports')->nullable()->unique()->constrained('material_reports', 'id_material_reports')->restrictOnDelete();
            $table->bigInteger('telegram_chat_id');
            $table->unsignedBigInteger('telegram_message_id');
            $table->unsignedBigInteger('telegram_user_id');
            $table->string('state', 20)->default('submitted')->index();
            foreach (MaterialReportFields::TEXT as $key => $label) {
                if ($key === 'alamat') {
                    $table->text($key)->nullable();
                } else {
                    $table->string($key)->nullable($key !== 'no_tq_sc_id');
                }
            }
            $table->text('koord_tiang')->nullable();
            foreach (MaterialReportFields::QUANTITIES as $key => $label) {
                $table->decimal($key, 15, 3)->nullable();
            }
            foreach (MaterialReportFields::COORDINATES as $key => $label) {
                $table->decimal($key, 11, 8)->nullable();
            }
            $table->timestamps();
            $table->unique(['telegram_chat_id', 'telegram_message_id']);
            $table->index(['id_branches', 'created_at']);
        });
        Schema::create('telegram_outbox', function (Blueprint $table) {
            $table->id('id_telegram_outbox');
            $table->foreignId('id_telegram_updates')->unique()->constrained('telegram_updates', 'id_telegram_updates')->restrictOnDelete();
            $table->bigInteger('telegram_chat_id');
            $table->text('payload'); // Encrypted reply text.
            $table->string('state', 20)->default('pending')->index();
            $table->unsignedInteger('attempts')->default(0);
            $table->unsignedBigInteger('telegram_message_id')->nullable();
            $table->timestamp('next_attempt_at')->nullable()->index();
            $table->timestamp('sent_at')->nullable();
            $table->string('error_code', 80)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['telegram_outbox', 'material_reports', 'telegram_updates', 'telegram_chats', 'telegram_identities'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
