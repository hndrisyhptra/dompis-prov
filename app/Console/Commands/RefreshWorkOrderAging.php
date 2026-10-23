<?php

namespace App\Console\Commands;

use App\Services\WorkOrderDerivedFields;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class RefreshWorkOrderAging extends Command
{
    protected $signature = 'olo:refresh-order-aging';

    protected $description = 'Refresh stored work order month, year and age without changing source hashes or progress';

    public function handle(): int
    {
        $count = 0;
        DB::table('work_orders')->orderBy('id_work_orders')->chunkById(200, function ($rows) use (&$count) {
            foreach ($rows as $row) {
                // Re-read while locked: an upload may change tgl_order during a daily refresh.
                DB::transaction(function () use ($row, &$count) {
                    $current = DB::table('work_orders')->where('id_work_orders', $row->id_work_orders)->lockForUpdate()->first();
                    if (! $current) {
                        return;
                    }
                    $values = WorkOrderDerivedFields::dates($current->tgl_order);
                    if (collect($values)->contains(fn ($value, $field) => $value === null ? $current->$field !== null : (string) $current->$field !== (string) $value)) {
                        DB::table('work_orders')->where('id_work_orders', $row->id_work_orders)->update($values);
                        $count++;
                    }
                }, 3);
            }
        }, 'id_work_orders');
        $this->info($count.' work order diperbarui.');

        return self::SUCCESS;
    }
}
