<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class MigrationRollbackTest extends TestCase
{
    use DatabaseMigrations;

    public function test_business_migrations_rollback_and_reapply_without_removing_existing_user_table(): void
    {
        $this->artisan('migrate:rollback', ['--step' => 20, '--force' => true])->assertSuccessful();
        $this->assertFalse(Schema::hasTable('work_orders'));
        $this->assertFalse(Schema::hasTable('wo_issues'));
        $this->assertTrue(Schema::hasTable('users'));
        $this->assertFalse(Schema::hasColumn('users', 'role'));
        $this->artisan('migrate', ['--force' => true])->assertSuccessful();
        $this->assertTrue(Schema::hasTable('work_orders'));
        $this->assertTrue(Schema::hasTable('wo_issues'));
        $this->assertTrue(Schema::hasColumn('users', 'active'));
    }
}
