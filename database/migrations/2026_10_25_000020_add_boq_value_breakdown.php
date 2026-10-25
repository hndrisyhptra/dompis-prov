<?php

use App\Services\Boq\BoqBreakdown;
use Brick\Math\BigDecimal;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Validate first, before DDL on MySQL. Do not reprice or rewrite historic BOQ revisions.
        foreach (DB::table('qe_boqs')->orderBy('id_qe_boqs')->cursor() as $boq) {
            $this->totals($boq);
        }
        // MySQL DDL is not transactional. Permit safe retry after an interrupted backfill.
        foreach (['material_total', 'labor_total'] as $column) {
            if (! Schema::hasColumn('qe_boqs', $column)) {
                Schema::table('qe_boqs', fn (Blueprint $table) => $table->decimal($column, 18, 2)->default(0));
            }
        }
        DB::transaction(function () {
            foreach (DB::table('qe_boqs')->orderBy('id_qe_boqs')->lockForUpdate()->get() as $boq) {
                $totals = $this->totals($boq);
                DB::table('qe_boqs')->where('id_qe_boqs', $boq->id_qe_boqs)->update(['material_total' => $totals['material_total'], 'labor_total' => $totals['labor_total']]);
            }
        });
    }

    public function down(): void
    {
        Schema::table('qe_boqs', fn (Blueprint $table) => $table->dropColumn(['material_total', 'labor_total']));
    }

    private function totals(object $boq): array
    {
        $totals = BoqBreakdown::totals(DB::table('qe_boq_items')->where('id_qe_boqs', $boq->id_qe_boqs)->whereNull('deleted_at')->get()->map(fn ($row) => (array) $row));
        if (! BigDecimal::of($totals['grand_total'])->isEqualTo((string) $boq->grand_total)) {
            throw new RuntimeException('Rekonsiliasi diperlukan untuk BOQ '.$boq->id_qe_boqs.'. Total header tidak sesuai item; data tidak diubah.');
        }

        return $totals;
    }
};
