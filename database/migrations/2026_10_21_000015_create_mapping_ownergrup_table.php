<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mapping_ownergrup', function (Blueprint $table) {
            $table->bigIncrements('id_mapping_ownergrup');
            $owner = $table->string('owner_grup')->unique();
            if (DB::getDriverName() === 'mysql') {
                $owner->collation('utf8mb4_bin');
            }
            $table->string('kelompok_ownergrup', 80);
            $table->boolean('active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mapping_ownergrup');
    }
};
