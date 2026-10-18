<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const TABLES = ['users', 'branches', 'branch_mappings', 'work_orders', 'sync_jobs', 'sync_job_rows', 'audit_logs', 'wo_status_events', 'wo_issues'];

    private const FOREIGN_KEYS = [
        ['user_branches', 'user_id', 'users', 'cascade'],
        ['user_branches', 'branch_id', 'branches', 'restrict'],
        ['branch_mappings', 'branch_id', 'branches', 'restrict'],
        ['work_orders', 'branch_id', 'branches', 'restrict'],
        ['sync_jobs', 'actor_id', 'users', 'restrict'],
        ['sync_job_rows', 'sync_job_id', 'sync_jobs', 'cascade'],
        ['audit_logs', 'actor_id', 'users', 'restrict'],
        ['wo_status_events', 'work_order_id', 'work_orders', 'restrict'],
        ['wo_status_events', 'actor_id', 'users', 'restrict'],
        ['wo_issues', 'work_order_id', 'work_orders', 'restrict'],
        ['wo_issues', 'actor_id', 'users', 'restrict'],
    ];

    private const NATURAL_KEYS = [
        'user_branches' => ['user_id', 'branch_id'],
    ];

    public function up(): void
    {
        $this->dropForeignKeys(false);
        foreach (self::TABLES as $table) {
            Schema::table($table, fn (Blueprint $blueprint) => $blueprint->renameColumn('id', 'id_'.$table));
        }
        foreach (self::NATURAL_KEYS as $table => $columns) {
            $this->addPrimaryKey($table, $columns);
        }
        $this->renameForeignKeys(false);
        $this->createForeignKeys(false);
    }

    public function down(): void
    {
        $this->dropForeignKeys(true);
        $this->renameForeignKeys(true);
        foreach (self::NATURAL_KEYS as $table => $columns) {
            $this->restoreNaturalKey($table, $columns);
        }
        foreach (array_reverse(self::TABLES) as $table) {
            Schema::table($table, fn (Blueprint $blueprint) => $blueprint->renameColumn('id_'.$table, 'id'));
        }
        $this->createForeignKeys(true);
    }

    private function dropForeignKeys(bool $renamed): void
    {
        foreach (array_reverse(self::FOREIGN_KEYS) as [$table, $column, $parent]) {
            Schema::table($table, fn (Blueprint $blueprint) => $blueprint->dropForeign([$renamed ? 'id_'.$parent : $column]));
        }
    }

    private function renameForeignKeys(bool $reverse): void
    {
        foreach ([...self::FOREIGN_KEYS, ['audit_logs', 'entity_id', 'entity']] as [$table, $column, $parent]) {
            Schema::table($table, fn (Blueprint $blueprint) => $blueprint->renameColumn($reverse ? 'id_'.$parent : $column, $reverse ? $column : 'id_'.$parent));
        }
    }

    private function createForeignKeys(bool $original): void
    {
        foreach (self::FOREIGN_KEYS as [$table, $column, $parent, $onDelete]) {
            Schema::table($table, fn (Blueprint $blueprint) => $blueprint
                ->foreign($original ? $column : 'id_'.$parent)
                ->references($original ? 'id' : 'id_'.$parent)->on($parent)->onDelete($onDelete));
        }
    }

    private function addPrimaryKey(string $table, array $columns): void
    {
        Schema::table($table, fn (Blueprint $blueprint) => $blueprint->unique($columns, $table.'_membership_unique'));
        if (DB::getDriverName() === 'sqlite') {
            // SQLite cannot ADD an autoincrement column. Populate it, then let Laravel rebuild the table.
            Schema::table($table, fn (Blueprint $blueprint) => $blueprint->unsignedBigInteger('id_'.$table)->nullable());
            $next = 0;
            foreach (DB::table($table)->orderBy($columns[0])->get($columns) as $row) {
                DB::table($table)->where((array) $row)->update(['id_'.$table => ++$next]);
            }
            Schema::table($table, function (Blueprint $blueprint) use ($table) {
                $blueprint->dropPrimary();
                $blueprint->bigIncrements('id_'.$table)->nullable(false)->change();
            });
        } else {
            Schema::table($table, fn (Blueprint $blueprint) => $blueprint->dropPrimary());
            Schema::table($table, fn (Blueprint $blueprint) => $blueprint->id('id_'.$table)->first());
        }
    }

    private function restoreNaturalKey(string $table, array $columns): void
    {
        Schema::table($table, fn (Blueprint $blueprint) => $blueprint->dropUnique($table.'_membership_unique'));
        if (DB::getDriverName() !== 'sqlite') {
            Schema::table($table, fn (Blueprint $blueprint) => $blueprint->unsignedBigInteger('id_'.$table)->autoIncrement(false)->change());
        }
        Schema::table($table, function (Blueprint $blueprint) use ($table, $columns) {
            $blueprint->dropPrimary();
            if (DB::getDriverName() === 'sqlite') {
                $blueprint->unsignedBigInteger('id_'.$table)->autoIncrement(false)->change();
            }
            $blueprint->primary($columns);
        });
        Schema::table($table, function (Blueprint $blueprint) use ($table) {
            $blueprint->dropColumn('id_'.$table);
        });
    }
};
