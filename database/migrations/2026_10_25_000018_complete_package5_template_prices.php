<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $reference = json_decode(file_get_contents(database_path('data/boq_package5_template_prices_20261025.json')), true, flags: JSON_THROW_ON_ERROR);
        DB::transaction(function () use ($reference) {
            foreach ($reference['prices'] as $row) {
                $key = ['designator_id' => $row['designator_id'], 'package_id' => $row['package_id']];
                if (! DB::table('designator_package_prices')->where($key)->exists()) {
                    DB::table('designator_package_prices')->insert($key + ['price' => $row['price'], 'created_at' => now(), 'updated_at' => now()]);
                }
            }
        });
    }

    public function down(): void
    {
        // Reference enrichment is retained: deleting prices could invalidate existing BOQs.
        // Rolling back the catalog migration drops the complete catalog explicitly.
    }
};
