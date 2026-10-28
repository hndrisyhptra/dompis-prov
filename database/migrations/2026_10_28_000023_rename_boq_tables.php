<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const TABLES = ['qe_boqs' => 'boq_jt', 'qe_boq_items' => 'boq_items', 'qe_boq_histories' => 'boq_histories'];

    private const COLUMNS = ['id_qe_boqs' => 'id_boq_jt', 'id_qe_boq_items' => 'id_boq_items', 'id_qe_boq_histories' => 'id_boq_histories'];

    public function up(): void
    {
        $this->rename(true);
    }

    public function down(): void
    {
        $this->rename(false);
    }

    private function rename(bool $forward): void
    {
        $tables = $forward ? self::TABLES : array_flip(self::TABLES);
        $columns = $forward ? self::COLUMNS : array_flip(self::COLUMNS);
        $current = [];
        foreach ($tables as $from => $to) {
            $old = Schema::hasTable($from);
            $new = Schema::hasTable($to);
            if ($old === $new) {
                throw new RuntimeException('Rekonsiliasi nama tabel BOQ diperlukan: '.$from.' / '.$to.'. Tidak ada tabel yang ditimpa.');
            }
            $current[$to] = $old ? $from : $to;
            $legacy = $forward ? $from : $to;
            $required = ['qe_boqs' => ['id_qe_boqs'], 'qe_boq_items' => ['id_qe_boq_items', 'id_qe_boqs'], 'qe_boq_histories' => ['id_qe_boq_histories', 'id_qe_boqs']][$legacy];
            foreach ($required as $column) {
                $before = $forward ? $column : self::COLUMNS[$column];
                $after = $columns[$before];
                if (Schema::hasColumn($current[$to], $before) === Schema::hasColumn($current[$to], $after)) {
                    throw new RuntimeException('Kolom BOQ lama/baru tidak konsisten. Rekonsiliasi diperlukan sebelum rename.');
                }
            }
            foreach (Schema::getForeignKeys($current[$to]) as $foreign) {
                if (count($foreign['columns']) !== 1 || ! in_array($foreign['columns'][0], ['id_work_orders', 'package_id', 'created_by', 'updated_by', 'id_qe_boqs', 'id_boq_jt', 'designator_id', 'id_users'], true)) {
                    throw new RuntimeException('Relasi BOQ tambahan perlu ditinjau sebelum rename.');
                }
            }
        }
        // Drop/recreate named constraints explicitly; MySQL DDL can be retried after interruption.
        foreach (array_reverse($current, true) as $table) {
            foreach (Schema::getForeignKeys($table) as $foreign) {
                Schema::table($table, fn (Blueprint $blueprint) => $blueprint->dropForeign(DB::getDriverName() === 'sqlite' ? $foreign['columns'] : $foreign['name']));
            }
        }
        foreach ($current as $to => $table) {
            if ($table !== $to) {
                Schema::rename($table, $to);
            }
            foreach ($columns as $before => $after) {
                if (Schema::hasColumn($to, $before)) {
                    Schema::table($to, fn (Blueprint $blueprint) => $blueprint->renameColumn($before, $after));
                }
            }
            foreach (Schema::getIndexes($to) as $index) {
                $name = strtr($index['name'], $columns + $tables);
                if (! $index['primary'] && $name !== $index['name']) {
                    // Older MariaDB lacks RENAME INDEX. Keep the existing key until its replacement exists.
                    $replacement = collect(Schema::getIndexes($to))->firstWhere('name', $name);
                    if ($replacement && ($replacement['columns'] !== $index['columns'] || $replacement['unique'] !== $index['unique'])) {
                        throw new RuntimeException('Index BOQ tujuan berbeda. Rekonsiliasi diperlukan; index tidak ditimpa.');
                    }
                    if (! $replacement) {
                        Schema::table($to, function (Blueprint $blueprint) use ($index, $name) {
                            $index['unique'] ? $blueprint->unique($index['columns'], $name) : $blueprint->index($index['columns'], $name);
                        });
                    }
                    // InnoDB may automatically remove a redundant implicit FK index after ADD INDEX.
                    if (Schema::hasIndex($to, $index['name'])) {
                        Schema::table($to, function (Blueprint $blueprint) use ($index) {
                            $index['unique'] ? $blueprint->dropUnique($index['name']) : $blueprint->dropIndex($index['name']);
                        });
                    }
                }
            }
        }
        $header = $forward ? 'boq_jt' : 'qe_boqs';
        $headerKey = $forward ? 'id_boq_jt' : 'id_qe_boqs';
        $items = $forward ? 'boq_items' : 'qe_boq_items';
        $history = $forward ? 'boq_histories' : 'qe_boq_histories';
        $foreignKeys = [
            [$header, 'id_work_orders', 'work_orders', 'id_work_orders', 'restrict'],
            [$header, 'package_id', 'packages', 'id_package', 'restrict'],
            [$header, 'created_by', 'users', 'id_users', 'set null'],
            [$header, 'updated_by', 'users', 'id_users', 'set null'],
            [$items, $headerKey, $header, $headerKey, 'restrict'],
            [$items, 'designator_id', 'designators', 'id_designator', 'restrict'],
            [$history, $headerKey, $header, $headerKey, 'restrict'],
            [$history, 'id_users', 'users', 'id_users', 'set null'],
        ];
        foreach ($foreignKeys as [$table, $column, $parent, $key, $delete]) {
            Schema::table($table, fn (Blueprint $blueprint) => $blueprint->foreign($column)->references($key)->on($parent)->onDelete($delete));
        }
    }
};
