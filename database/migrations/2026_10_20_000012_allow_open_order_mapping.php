<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mapping_order', function (Blueprint $table) {
            $table->enum('mapping_order', ['STUCK', 'PS', 'CANCLWORK', 'OPEN'])->change();
        });
    }

    public function down(): void
    {
        if (DB::table('mapping_order')->where('mapping_order', 'OPEN')->exists()) {
            throw new RuntimeException('Master masih berisi OPEN. Rekonsiliasi aturan OPEN sebelum rollback; kategori tidak dihapus atau diubah otomatis.');
        }
        Schema::table('mapping_order', function (Blueprint $table) {
            $table->enum('mapping_order', ['STUCK', 'PS', 'CANCLWORK'])->change();
        });
    }
};
