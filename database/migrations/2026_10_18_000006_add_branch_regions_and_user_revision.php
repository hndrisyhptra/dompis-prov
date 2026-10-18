<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('branches', function (Blueprint $table) {
            $table->foreignId('region_id')->nullable()->constrained('regions', 'id_regions')->restrictOnDelete();
        });
        Schema::table('users', fn (Blueprint $table) => $table->unsignedInteger('management_revision')->default(0));
        $regions = DB::table('regions')->whereNull('deleted_at')->get();
        foreach (DB::table('branches')->whereNotNull('regional')->get(['id_branches', 'regional']) as $branch) {
            $label = mb_strtoupper(trim($branch->regional));
            $matches = $regions->filter(fn ($region) => in_array($label, [mb_strtoupper(trim($region->name)), mb_strtoupper(trim($region->code))], true));
            if ($matches->count() === 1) {
                DB::table('branches')->where('id_branches', $branch->id_branches)->update(['region_id' => $matches->first()->id_regions]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('management_revision'));
        Schema::table('branches', function (Blueprint $table) {
            $table->dropForeign(['region_id']);
            $table->dropColumn('region_id');
        });
    }
};
