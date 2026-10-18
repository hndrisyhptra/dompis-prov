<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SyncJob extends Model
{
    protected $primaryKey = 'id_sync_jobs';

    protected $guarded = ['id_sync_jobs'];

    protected $hidden = ['snapshot'];

    protected function casts(): array
    {
        return ['snapshot' => 'encrypted:array', 'stats' => 'array', 'started_at' => 'datetime', 'finished_at' => 'datetime', 'expires_at' => 'datetime'];
    }

    public function rows()
    {
        return $this->hasMany(SyncJobRow::class, 'id_sync_jobs');
    }

    public function actor()
    {
        return $this->belongsTo(User::class, 'id_users');
    }

    public function busy(): bool
    {
        return in_array($this->state, ['queued', 'running', 'retrying'], true);
    }
}
