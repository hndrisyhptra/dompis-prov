<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SyncJobRow extends Model
{
    protected $primaryKey = 'id_sync_job_rows';

    public $timestamps = false;

    protected $guarded = ['id_sync_job_rows'];

    protected $hidden = ['payload'];

    protected function casts(): array
    {
        return ['field_changes' => 'array', 'payload' => 'encrypted:array'];
    }
}
